<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Transforme un parametre de route en identifiant type.
 *
 * `#[Route('/api/conversations/{conversationId}')]` avec un argument
 * `ConversationId $conversationId` arrive donc deja valide dans le controleur.
 * Sans cela, chaque controleur repeterait `ConversationId::fromString(...)`, et
 * `#[IsGranted(..., subject: 'conversationId')]` ne recevrait qu'une chaine, que
 * le voter ne saurait pas distinguer d'un identifiant d'autre nature.
 *
 * Un identifiant malforme leve InvalidIdentifierException, traduite en 422.
 */
final readonly class UlidIdentifierValueResolver implements ValueResolverInterface
{
    /** @return iterable<AbstractUlidIdentifier> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();

        if (null === $type || !is_subclass_of($type, AbstractUlidIdentifier::class)) {
            return [];
        }

        $value = $request->attributes->get($argument->getName());

        if (!is_string($value)) {
            return [];
        }

        return [$type::fromString($value)];
    }
}
