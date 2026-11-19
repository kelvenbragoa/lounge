<?php

namespace App\Exceptions;

use DomainException;

class CardException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'card_error',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Cartão não encontrado.', 'card_not_found', 404);
    }

    public static function notActive(string $status): self
    {
        $label = match ($status) {
            'blocked' => 'bloqueado',
            'lost' => 'marcado como perdido',
            'returned' => 'devolvido (não registado)',
            default => $status,
        };

        return new self("Cartão {$label}. Operação não permitida.", 'card_not_active', 409);
    }

    public static function shiftClosed(): self
    {
        return new self('Abra o caixa de cartões antes de movimentar dinheiro.', 'card_shift_closed', 409);
    }
}
