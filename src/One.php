<?php

namespace src;

class One
{
    public function coveredBy1()
    {
        return $this->coveredBy1And2() - 2;
    }

    public function coveredBy2()
    {
        return $this->coveredBy1And2() - 1;
    }

    public function coveredBy1And2()
    {
        return 3;
    }
}
