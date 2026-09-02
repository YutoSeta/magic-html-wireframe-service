<?php

namespace App\Layout;

final class LayoutDesignKey
{
    public static function forNode(string $role, string $id, string $qualifier = ''): string
    {
        $identity = [$qualifier.$role];
        if ($role === 'Form') {
            $identity[] = 'm-form='.$id;
        }
        $identity[] = 'id='.$id;

        return 'd_'.substr(hash('sha256', implode('|', $identity)), 0, 12);
    }
}
