<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ReservedPseudonymValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ReservedPseudonym) {
            throw new UnexpectedTypeException($constraint, ReservedPseudonym::class);
        }

        if (!is_string($value) || $value === '') {
            return;
        }

        if (in_array(ReservedPseudonym::normalize($value), ReservedPseudonym::RESERVED, true)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
