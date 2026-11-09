<?php

namespace App\Enums;

enum GridGrade: string
{
    case APlus = 'A+';
    case A = 'A';
    case BPlus = 'B+';
    case B = 'B';
    case CPlus = 'C+';
    case C = 'C';

    /**
     * Grade a 0-100 quality score.
     */
    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 85 => self::APlus,
            $score >= 75 => self::A,
            $score >= 65 => self::BPlus,
            $score >= 55 => self::B,
            $score >= 45 => self::CPlus,
            default => self::C,
        };
    }

    /**
     * Whether this grade is the given grade or better.
     */
    public function isAtLeast(self $minimum): bool
    {
        $order = array_flip(array_column(self::cases(), 'value'));

        return $order[$this->value] <= $order[$minimum->value];
    }

    /**
     * Share of the total margin a bot with this grade should receive, in percent.
     */
    public function marginAllocationPct(): float
    {
        return match ($this) {
            self::APlus => 15,
            self::A => 12,
            self::BPlus => 8,
            self::B => 5,
            self::CPlus => 3,
            self::C => 2,
        };
    }
}
