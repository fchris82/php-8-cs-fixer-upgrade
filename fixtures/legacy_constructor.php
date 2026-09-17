<?php

class LegacyUser
{
    /** @var string */
    private $email;

    /** @var int */
    private $score;

    public function __construct($email, $score)
    {
        $this->email = $email;
        $this->score = $score;
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param int $score
     */
    public function setScore($score)
    {
        $this->score = $score;
    }
}
