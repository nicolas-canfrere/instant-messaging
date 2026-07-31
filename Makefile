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

##@ Stack
# Point d'entrée d'un poste neuf : de `git clone` à l'application qui tourne.
# Chaque étape est idempotente, la cible se relance donc sans rien casser.
# Les étapes sont enchaînées dans la recette et non listées en prérequis :
# l'ordre est alors garanti même sous `make -j`.
setup: .env ## Set up a fresh checkout, from clone to running app
	@$(MAKE) --no-print-directory install
	@$(MAKE) --no-print-directory build
	@$(MAKE) --no-print-directory up
	@$(MAKE) --no-print-directory migrate
	@$(MAKE) --no-print-directory fixtures
	@printf '\n\033[32mPret.\033[0m http://localhost:8080 — alice / password\n\n'

# Cible fichier, sans prérequis : make ne l'exécute que si `.env` est absent.
# C'est ce qui rend `setup` relançable sans écraser un secret déjà en place ni
# des UID/GID ajustés à la main. La déclarer `.PHONY` la régénérerait à chaque
# appel — le `.env` est justement le seul fichier qu'on ne veut jamais perdre.
# (Le nom commençant par un point, l'awk de `.PHONY` en haut ne l'attrape pas.)
.env:
	@sed -e "s|^MERCURE_JWT_SECRET=.*|MERCURE_JWT_SECRET=$$(openssl rand -hex 32)|" \
		-e "s|^USER_ID=.*|USER_ID=$(shell id -u)|" \
		-e "s|^GROUP_ID=.*|GROUP_ID=$(shell id -g)|" \
		.env.example > .env
	@echo ".env cree : secret Mercure tire au hasard, UID/GID de l'hote."

up: ## Start the whole stack in the background
	@$(DOCKER_COMPOSE) up -d --wait

# Sans `-v` : la base et les données du hub survivent. Pour tout effacer, c'est
# explicite et ça ne mérite pas de raccourci — `docker compose down -v`.
down: ## Stop the stack. The database and hub volumes are kept
	@$(DOCKER_COMPOSE) down --remove-orphans

ps: ## Show the state of every container
	@$(DOCKER_COMPOSE) ps

build: ## Rebuild the images
	@$(DOCKER_COMPOSE) build

logs: ## Follow the logs. Usage: make logs SERVICE=backend
	@$(DOCKER_COMPOSE) logs -f $(SERVICE)

restart: ## Restart a service. Usage: make restart SERVICE=frontend
	@$(DOCKER_COMPOSE) restart $(SERVICE)

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

# La commande commence par un TRUNCATE : elle remet la base de dev dans un état
# jouable connu (alice, bob, carol — un direct et un groupe), elle ne complète
# pas l'existant.
fixtures: ## Wipe the dev database and load a playable data set
	$(DOCKER_COMPOSE_RUN) backend bin/console app:fixtures:load

##@ Frontend
# `exec` et non `run --rm` : `node_modules` est un volume anonyme (voir le
# commentaire dans compose.yaml), donc propre à chaque conteneur. Un `run` en
# fabriquerait un neuf et jetable, dépourvu de tout paquet installé depuis la
# construction de l'image — les tests échoueraient sur des dépendances
# introuvables, sans rapport avec le code testé. D'où la dépendance à `up` :
# on exécute dans le conteneur qui tourne, le seul qui ait le bon node_modules.
FRONTEND_EXEC = $(DOCKER_COMPOSE) exec $(DOCKER_COMPOSE_RUN_FLAGS) frontend

front-install: up ## Install the npm dependencies in the running container
	@$(FRONTEND_EXEC) npm install

front-test: up ## Run the frontend test suite (Vitest)
	@$(FRONTEND_EXEC) npm test

front-typecheck: up ## Type-check the frontend (tsc --noEmit)
	@$(FRONTEND_EXEC) npm run typecheck

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

# `backend-test` construit depuis le même `backend/Dockerfile` que `backend`,
# mais Docker ne recompile jamais une image toute seule : ajouter une
# extension au Dockerfile (ex. gd/amqp en tâche 1) laisse l'image de test
# périmée jusqu'à un rebuild explicite. Le symptôme ne dit pas « image
# périmée » — il dit « Call to undefined function imagecreatefromstring() »,
# ce qui se lit comme un bug de code. Cette cible est le rappel : si
# `unit-test`/`functional-test` échoue sur une fonction introuvable après
# avoir touché le Dockerfile, lancer `make test-build` avant de chercher plus
# loin.
test-build: ## Rebuild the test images (needed after editing backend/Dockerfile)
	@$(DOCKER_COMPOSE_TEST) build

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
	@$(DOCKER_COMPOSE_TEST) up -d --wait postgres-test redis-test minio-test
	@$(DOCKER_COMPOSE_TEST_RUN) backend-test sh -c "php bin/console doctrine:database:create --if-not-exists -n -q \
	&& php bin/console doctrine:migrations:migrate -n -q --allow-no-migration \
	&& php bin/console app:fixtures:load -q \
	&& php vendor/bin/phpunit --testsuite=Functional --stop-on-error --stop-on-failure $(ARGS)" ; \
	CODE=$$? ; \
	$(MAKE) --no-print-directory test-down ; \
	exit $$CODE

test-down: ## Tear down the test stack and wipe its volumes
	@$(DOCKER_COMPOSE_TEST) down --remove-orphans -v
