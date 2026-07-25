.DEFAULT_GOAL: help

.PHONY: $(filter-out vendor, $(shell awk -F: '/^[a-zA-Z0-9_%-]+:/ { print $$1 }' $(MAKEFILE_LIST) | sort | uniq))

INTERACTIVE := $(shell [ -t 0 ] && echo 1 || echo 0)
ifeq ($(INTERACTIVE), 1)
	DOCKER_FLAGS += -t
else
	DOCKER_COMPOSE_RUN_FLAGS += -T
endif

COMPOSER_HOME ?= ${HOME}/.composer
COMPOSER_CLI = docker run $(DOCKER_FLAGS) -i --rm \
	--env COMPOSER_HOME=${COMPOSER_HOME} \
	--volume ${COMPOSER_HOME}:${COMPOSER_HOME} \
	--volume ${PWD}:/app \
	--user $(shell id -u):$(shell id -g) \
	--workdir /app/backend \
	composer:2.10.2

DOCKER_COMPOSE_FILE ?= compose.yaml
DOCKER_COMPOSE = docker compose -f $(DOCKER_COMPOSE_FILE)
# `--no-deps` sur les cibles d'analyse : php-cs-fixer, phpstan et deptrac lisent
# des fichiers, jamais la base. Inutile de lever postgres pour les faire tourner.
DOCKER_COMPOSE_RUN = $(DOCKER_COMPOSE) --progress quiet run --rm --remove-orphans $(DOCKER_COMPOSE_RUN_FLAGS)

help: ## Display this help
	@awk 'BEGIN {FS = ":.* ##"; printf "\n\033[1mUsage:\033[0m\n  make \033[32m<target>\033[0m\n"} /^[a-zA-Z_-]+:.* ## / { printf "  \033[33m%-25s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ PHP: Installation
install: backend/vendor ## Install all necessary things

backend/vendor: backend/composer.json backend/composer.lock
	@$(COMPOSER_CLI) install --ignore-platform-reqs
	@touch backend/vendor

composer-req: ## Add a PHP package. Usage: make composer-req PACKAGES="vendor/package [--dev]"
	$(COMPOSER_CLI) require --ignore-platform-reqs $(PACKAGES)

##@ PHP CLI:
php-cli: ## PHP runtime
	$(DOCKER_COMPOSE_RUN) --no-deps backend sh

##@ Database
# `generate` cree une classe de migration vide, a remplir en SQL explicite.
# Pas de `doctrine:migrations:diff` : il deduit le schema des metadonnees de
# l'ORM, que ce projet n'installe pas. Les migrations s'ecrivent a la main.
generate-migration: ## Generate a new empty migration in backend/migrations/
	$(DOCKER_COMPOSE_RUN) --no-deps backend bin/console doctrine:migrations:generate

# Joue les migrations sur la base de dev. Celle des tests est migree par
# `functional-test`, sur sa propre stack.
# Usage : make migrate OPTIONS="--dry-run"
migrate: ## Run migrations on the dev database. OPTIONS="--dry-run"
	$(DOCKER_COMPOSE_RUN) backend bin/console doctrine:migrations:migrate -n --allow-no-migration $(OPTIONS)

migration-status: ## Show which migrations have been applied
	$(DOCKER_COMPOSE_RUN) backend bin/console doctrine:migrations:list

##@ Code analysis
static-code-analysis: ## Code analysis with PHPStan
	$(DOCKER_COMPOSE_RUN) --no-deps backend sh -c "php bin/console cache:clear --env=test \
	&& php bin/console cache:warmup --env=test \
	&& php ./vendor/bin/phpstan analyse --memory-limit=512M"

apply-cs: ## Apply coding standards with PHP CS Fixer
	$(DOCKER_COMPOSE_RUN) --no-deps backend vendor/bin/php-cs-fixer fix --show-progress=dots --diff --config=.php-cs-fixer.dist.php

check-cs: ## Check coding standards with PHP CS Fixer (no auto-fix)
	$(DOCKER_COMPOSE_RUN) --no-deps backend vendor/bin/php-cs-fixer check --diff --config=.php-cs-fixer.dist.php

deptrac: ## Check architectural layer dependencies + bounded context boundaries
	$(DOCKER_COMPOSE_RUN) --no-deps backend vendor/bin/deptrac analyse --no-progress --fail-on-uncovered
	$(DOCKER_COMPOSE_RUN) --no-deps backend vendor/bin/deptrac --config-file=deptrac-contexts.yaml analyse --no-progress --fail-on-uncovered

##@ Tests
# Stack de test isolée : nom de projet distinct, donc conteneurs, réseau et
# volumes séparés de ceux du dev. On peut la détruire sans rien perdre.
DOCKER_COMPOSE_TEST_FILE ?= compose.test.yaml
DOCKER_COMPOSE_TEST = docker compose --progress quiet -p instant-messaging-test -f $(DOCKER_COMPOSE_TEST_FILE)
DOCKER_COMPOSE_TEST_RUN = $(DOCKER_COMPOSE_TEST) run --rm --remove-orphans $(DOCKER_COMPOSE_RUN_FLAGS)

test: unit-test functional-test ## Run the whole test suite

# Le domaine et l'application ne touchent ni la base ni le noyau : `--no-deps`
# évite de lever postgres pour rien.
#
# `--do-not-fail-on-empty-test-suite` : tant que `tests/Unit/` est vide, PHPUnit
# sort en erreur sur « No tests executed! ». Le flag est posé ici et pas dans
# `phpunit.dist.xml` pour que la suite fonctionnelle, elle, reste stricte — une
# suite qui ne matche plus rien doit continuer d'echouer bruyamment.
unit-test: ## Run unit tests. Usage: make unit-test ARGS="--filter=UlidIdentifierTest"
	@$(DOCKER_COMPOSE_TEST_RUN) --no-deps backend-test vendor/bin/phpunit --testsuite=Unit \
		--stop-on-error --stop-on-failure --do-not-fail-on-empty-test-suite $(ARGS)

# `test-down` en préambule autant qu'en conclusion : le premier garantit une
# base vierge même si l'exécution précédente a été interrompue, le second rend
# la main propre. Le code de sortie de PHPUnit est propagé au travers du
# nettoyage, sinon un échec de test passerait pour un succès en CI.
functional-test: test-down ## Run functional tests. Usage: make functional-test ARGS="--filter=PingTest"
	@$(DOCKER_COMPOSE_TEST) up -d --wait postgres-test
	@$(DOCKER_COMPOSE_TEST_RUN) backend-test sh -c "php bin/console doctrine:database:create --if-not-exists -n -q \
	&& php bin/console doctrine:migrations:migrate -n -q --allow-no-migration \
	&& php bin/console app:fixtures:load -q \
	&& php vendor/bin/phpunit --testsuite=Functional --stop-on-error --stop-on-failure $(ARGS)" ; \
	CODE=$$? ; \
	$(MAKE) --no-print-directory test-down ; \
	exit $$CODE

test-down: ## Tear down the test stack and wipe its volumes
	@$(DOCKER_COMPOSE_TEST) down --remove-orphans -v
