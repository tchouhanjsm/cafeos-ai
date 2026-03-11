<?php

namespace App\Services;

class OrderStateMachine
{

    protected $transitions = [

        'open' => ['sent_to_kitchen'],

        'sent_to_kitchen' => ['cooking'],

        'cooking' => ['ready'],

        'ready' => ['served'],

        'served' => ['billed'],

        'billed' => ['paid']

    ];


    public function canTransition($current,$next)
    {

        if(!isset($this->transitions[$current])){
            return false;
        }

        return in_array($next,$this->transitions[$current]);

    }

}