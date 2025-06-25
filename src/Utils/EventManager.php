<?php

namespace SonicGame\Utils;

class EventManager
{
    private static array $listeners = [];

    /**
     * Écouter un événement
     */
    public static function on(string $eventName, callable $callback): void
    {
        if (!isset(self::$listeners[$eventName])) {
            self::$listeners[$eventName] = [];
        }
        self::$listeners[$eventName][] = $callback;
    }

    /**
     * Émettre un événement
     */
    public static function emit(string $eventName, array $data = []): void
    {
        if (isset(self::$listeners[$eventName])) {
            foreach (self::$listeners[$eventName] as $callback) {
                $callback($data);
            }
        }
    }

    /**
     * Supprimer tous les listeners d'un événement
     */
    public static function off(string $eventName): void
    {
        if (isset(self::$listeners[$eventName])) {
            unset(self::$listeners[$eventName]);
        }
    }

    /**
     * Supprimer tous les listeners
     */
    public static function clear(): void
    {
        self::$listeners = [];
    }
} 