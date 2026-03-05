<?php

declare(strict_types=1);

class UserService
{
    private array $users = [];

    public function findActive(): array
    {
        $result = [];
        foreach ($this->users as $user) {
            if ($user['active']) {
                if ($user['verified']) {
                    $result[] = $user;
                }
            }
        }
        return $result;
    }

    public function getName(int $id): string
    {
        foreach ($this->users as $user) {
            if ($user['id'] === $id) {
                return $user['name'];
            }
        }
        return '';
    }
}
