<?php

namespace SonicGame\Utils;

/**
 * The game's language: French (the texts of the code) or English (this table). TextRenderer translates every
 * text it draws (an exact match of the table, else unchanged); texts made of parts are put together with
 * t() and a {name} pattern.
 *
 * Language: the menu OPTIONS > LANGUE, else the page's SONIC_LANG (the site or the browser's language), else
 * the system's (LANG=fr_FR...: French, anything else: English).
 */
final class Lang
{
    private static ?string $lang = null;

    private const EN = [
        // title, menus
        'DEMARRER' => 'START', 'OPTIONS' => 'OPTIONS', 'MULTIJOUEUR' => 'MULTIPLAYER', 'EN LIGNE' => 'ONLINE',
        'MEILLEURS TEMPS' => 'BEST TIMES', 'SITE WEB' => 'WEBSITE', 'RETOUR' => 'BACK', 'QUITTER' => 'QUIT', 'LANCER' => 'START',
        'MODE' => 'MODE', 'ZONE' => 'ZONE', 'SONICS' => 'SONICS', 'IA' => 'AI', 'NOM' => 'NAME', 'LANGUE' => 'LANGUAGE',
        'AUTO' => 'AUTO', 'FRANCAIS' => 'FRENCH', 'ANGLAIS' => 'ENGLISH',
        'FILTRE CRT' => 'CRT FILTER', 'VITESSE' => 'SPEED', 'JUKEBOX' => 'JUKEBOX', 'CHEATS' => 'CHEATS',
        'MUSIQUE' => 'MUSIC', 'BRUITAGE' => 'SOUND', 'STOP' => 'STOP', 'GHOST' => 'GHOST', 'CARTE' => 'MAP',
        'EFFACER GHOSTS' => 'ERASE GHOSTS', 'CONFIRMER ?' => 'CONFIRM ?', 'GHOSTS EFFACES' => 'GHOSTS ERASED',
        'EFFACER' => 'ERASE', 'OK' => 'OK', 'ESPACE' => 'SPACE', 'AUCUN TEMPS' => 'NO TIME YET',
        'NOUVEAU RECORD !' => 'NEW RECORD !', 'TITRE' => 'TITLE', 'DEPART' => 'START',
        // the levels of the site's editor
        'NIVEAUX DU SITE' => 'SITE LEVELS', 'NIVEAU DU SITE' => 'SITE LEVEL', 'CHARGEMENT DU NIVEAU...' => 'LOADING THE LEVEL...', 'NIVEAU INDISPONIBLE' => 'LEVEL UNAVAILABLE', 'REESSAYER' => 'RETRY', 'PAGE' => 'PAGE', 'A TOI' => 'YOURS', 'PRIVE' => 'PRIVATE',
        'CHARGEMENT...' => 'LOADING...', 'CHARGEMENT DU NIVEAU...' => 'LOADING THE LEVEL...',
        'PAS DE CONNEXION AU SITE' => 'CANNOT REACH THE SITE', 'NIVEAU INDISPONIBLE' => 'LEVEL UNAVAILABLE',
        'AUCUN NIVEAU POUR LE MOMENT' => 'NO LEVEL YET', 'MIEUX NOTES' => 'BEST RATED', 'NOUVEAUTES' => 'NEW', 'PLUS JOUES' => 'MOST PLAYED',
        'COUPS DE COEUR' => 'MOST LIKED', 'MES FAVORIS' => 'MY FAVOURITES', 'MES NIVEAUX' => 'MY LEVELS', 'TON AVIS' => 'YOUR OPINION',
        'NOTE' => 'STARS', 'FAVORI' => 'FAVOURITE', 'ENVOYER' => 'SEND', 'PLUS TARD' => 'LATER', 'CONNECTE TOI SUR LE SITE' => 'SIGN IN ON THE SITE',
        'PAS ENCORE DE FAVORI' => 'NO FAVOURITE YET', 'RECORD {time} {name}' => 'RECORD {time} {name}', 'COMPTE {name}' => 'ACCOUNT {name}',
        'TES NIVEAUX PRIVES  VIA LE SITE' => 'YOUR PRIVATE LEVELS  VIA THE SITE',
        // cheats
        'VIES INFINIES' => 'INFINITE LIVES', 'CONTINUES INFINIS' => 'INFINITE CONTINUES', 'INVINCIBLE' => 'INVINCIBLE',
        'BOUCLIER PERMANENT' => 'PERMANENT SHIELD', '50 ANNEAUX' => '50 RINGS', '6 EMERAUDES' => '6 EMERALDS', 'VITESSE TURBO' => 'TURBO SPEED', 'NIVEAU' => 'LEVEL',
        // jukebox
        'ACTE TERMINE' => 'ACT CLEAR', 'PAR {name}' => 'BY {name}', 'MORT' => 'DEATH', 'EMERAUDE' => 'EMERALD', 'TOUTES EMERAUDES' => 'ALL EMERALDS',
        'FIN' => 'ENDING', 'BOSS' => 'BOSS', 'SPECIAL STAGE' => 'SPECIAL STAGE',
        // difficulty
        'FACILE' => 'EASY', 'MOYEN' => 'MEDIUM', 'DUR' => 'HARD',
        // multiplayer
        'COURSE' => 'RACE', 'DRAPEAU' => 'FLAG', 'ANNEAUX' => 'RINGS', 'DEATHMATCH' => 'DEATHMATCH',
        'LE PREMIER A L ARRIVEE GAGNE' => 'FIRST TO THE FINISH WINS', 'SAUTE OU ROULE SUR LES AUTRES' => 'JUMP OR ROLL ON THE OTHERS',
        'RAMENE LE DRAPEAU A TA BASE' => 'BRING THE FLAG TO YOUR BASE', 'TOUCHE TU LE LACHES  3 POINTS' => 'HIT YOU DROP IT  3 POINTS',
        'TOUCHE TU PRENDS SES ANNEAUX' => 'HIT TAKES THEIR RINGS', '3 MIN  LE PLUS D ANNEAUX GAGNE' => '3 MIN  MOST RINGS WINS',
        '3 MIN  LE PLUS DE KO GAGNE' => '3 MIN  MOST KOS WINS',
        'BLEUS' => 'BLUES', 'ROUGES' => 'REDS', 'BASE' => 'BASE', 'VICTOIRE' => 'VICTORY', 'GO' => 'GO',
        'PERSONNE N EST ARRIVE' => 'NOBODY FINISHED', 'SAUT POUR CONTINUER' => 'JUMP TO GO ON',
        'T OU ENTREE : PARLER' => 'T OR ENTER : CHAT', 'L HOTE EST PARTI' => 'THE HOST LEFT', 'ABANDON' => 'GAVE UP',
        '{name} GAGNE' => '{name} WINS', 'EGALITE {score}' => 'DRAW {score}', 'LES {team} GAGNENT {score}' => 'THE {team} WIN {score}',
        'POINT POUR LES {team}' => 'POINT FOR THE {team}', '{name} A LE DRAPEAU' => '{name} HAS THE FLAG',
        '{name} REMPLACE PAR UN BOT' => '{name} REPLACED BY A BOT', '{name} REJOINT LA PARTIE' => '{name} JOINS THE MATCH', 'BLEUS {a} - {b} ROUGES' => 'BLUES {a} - {b} REDS',
        '{n} ANNEAUX' => '{n} RINGS', '{n} KO' => '{n} KO', ' ARRIVE' => ' FINISHED', '{a} CONTRE {b}' => '{a} VS {b}',
        // online
        'SALON PUBLIC' => 'PUBLIC ROOM', 'SALON PRIVE' => 'PRIVATE ROOM', 'REJOINDRE' => 'JOIN', 'PARLER' => 'CHAT',
        'PARTAGER' => 'SHARE', 'VIENS JOUER A SONIC AVEC MOI ! SALON {code}' => 'COME PLAY SONIC WITH ME ! ROOM {code}', 'LIEN {name}' => 'LINK {name}', 'LIEN ENVOYE' => 'LINK SENT', 'LIEN' => 'LINK', 'RETOUR AU SITE' => 'BACK TO THE SITE',
        'PUBLIC  AVEC D AUTRES JOUEURS' => 'PUBLIC  WITH OTHER PLAYERS', 'PRIVE  UN CODE POUR TES AMIS' => 'PRIVATE  A CODE FOR YOUR FRIENDS',
        'REGLAGES DANS MULTIJOUEUR' => 'SETTINGS IN MULTIPLAYER', 'DECONNECTE' => 'DISCONNECTED', 'CONNEXION' => 'CONNECTING',
        'SALON {code}' => 'ROOM {code}', 'SALON PUBLIC {code}' => 'PUBLIC ROOM {code}', '{name} HOTE' => '{name} HOST',
        'LANCE QUAND TOUT LE MONDE EST LA' => 'START WHEN EVERYBODY IS HERE', 'EN ATTENTE DE L HOTE' => 'WAITING FOR THE HOST',
        'SUR LE SITE' => 'ON THE SITE', 'CODE DU SALON' => 'ROOM CODE', 'TON NOM' => 'YOUR NAME',
        'VU PAR LES AUTRES JOUEURS' => 'SEEN BY THE OTHER PLAYERS', 'MESSAGE' => 'MESSAGE', 'ENTREE ENVOIE  ECHAP ANNULE' => 'ENTER SENDS  ESCAPE CANCELS',
        'SALON PUBLIC  DIS BONJOUR !' => 'PUBLIC ROOM  SAY HELLO !', '{name} EST ARRIVE' => '{name} IS HERE', '{name} EST PARTI' => '{name} LEFT',
        '{name} EST SUR LE SITE' => '{name} IS ON THE SITE', '{name} A QUITTE LE SITE' => '{name} LEFT THE SITE',
        'CE SALON N EST PAS UNE PARTIE DE SONIC' => 'THIS ROOM IS NOT A SONIC GAME', 'AUTRE VERSION  RECHARGE LA PAGE' => 'OTHER VERSION  RELOAD THE PAGE',
        'SALON INTROUVABLE' => 'ROOM NOT FOUND', 'SALON COMPLET' => 'ROOM FULL', 'SERVEUR INJOIGNABLE' => 'SERVER UNREACHABLE',
        'CONNEXION PERDUE' => 'CONNECTION LOST', 'ERREUR' => 'ERROR',
        // best times, ranks
        'ZONE {zone} ACTE {act}' => 'ZONE {zone} ACT {act}',
        // replay
        'REPLAY' => 'REPLAY', 'PAUSE' => 'PAUSE', '{n} ANNEAUX  {time}' => '{n} RINGS  {time}',
    ];

    public static function set(?string $lang): void
    {
        self::$lang = in_array($lang, ['fr', 'en'], true) ? $lang : null;
    }

    /** 'fr' or 'en'. */
    public static function get(): string
    {
        return self::$lang ??= self::detect();
    }

    /** The page (SONIC_LANG: the site, or the browser), else the system. */
    public static function detect(): string
    {
        if (defined('SONIC_LANG') && in_array(constant('SONIC_LANG'), ['fr', 'en'], true)) return constant('SONIC_LANG');
        $system = strtolower((string)(getenv('LC_ALL') ?: getenv('LC_MESSAGES') ?: getenv('LANG')));
        return str_starts_with($system, 'fr') ? 'fr' : 'en';
    }

    /** $text (French) in the game's language, {key} replaced by $values. */
    public static function t(string $text, array $values = []): string
    {
        if (self::get() === 'en' && isset(self::EN[$text])) $text = self::EN[$text];
        foreach ($values as $key => $value) $text = str_replace('{' . $key . '}', (string)$value, $text);
        return $text;
    }

    /** "1ER", "2E" / "1ST", "2ND", "3RD", "4TH". */
    public static function rank(int $place): string
    {
        if (self::get() === 'fr') return $place === 1 ? '1ER' : $place . 'E';
        return $place . match ($place % 10 === 1 && $place !== 11 ? 1 : ($place % 10 === 2 && $place !== 12 ? 2 : ($place % 10 === 3 && $place !== 13 ? 3 : 0))) {
            1 => 'ST', 2 => 'ND', 3 => 'RD', default => 'TH',
        };
    }
}
