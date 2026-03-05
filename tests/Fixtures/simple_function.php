<?php

declare(strict_types=1);

function greet(string $name): string
{
    $greeting = 'Hello, ' . $name;
    return $greeting;
}
