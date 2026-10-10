<?php

namespace SonicGame\Entities\Objects;

/** Frames of tileset/sprites/tileset-rom.png, rendered from the ROM sprite art by tools/romsprites.py. */
final class RomSprites
{
    public const SHEET = 'rom';

    public const GH_PLATFORM = [0, 0, 32, 16];
    public const BRIDGE_PLATFORM = [34, 0, 32, 16];
    public const LOG_PLATFORM = [68, 0, 32, 16];
    public const LOG_VERTICAL = [102, 0, 16, 32];
    public const LOG_ROLL = [[120, 0, 16, 16], [138, 0, 16, 16], [156, 0, 16, 16]];
    public const SKY_PLATFORM = [174, 0, 32, 16];
    public const PROPELLER = [[208, 0, 8, 32], [218, 0, 8, 32], [228, 0, 8, 32], [238, 0, 8, 16]];
    public const SEESAW_PAD = [248, 0, 24, 16];
    public const SEESAW_WEIGHT = [274, 0, 16, 16];
    // second row: single 8x16 sprites of the Sky Base art (x = index * 10)
    public const SKY_TILE_Y = 66;
    public const SKY_TILES = [0x66, 0x70, 0x72, 0x4C, 0x4E, 0x6C, 0x6E, 0x64, 0x68, 0x6A, 0x48, 0x4A, 0x50, 0x52,
        0x74, 0x76, 0x78, 0x7A, 0x7C, 0x7E, 0x02, 0x04, 0x1C, 0x3C, 0x1E, 0x3E, 0x38, 0x3A, 0x1A, 0x06, 0x08];

    /** Sheet rectangle of a Sky Base sprite tile (8x16). */
    public static function skyTile(int $tile): array
    {
        return [array_search($tile, self::SKY_TILES, true) * 10, self::SKY_TILE_Y, 8, 16];
    }

    public const UNIDUS_BALL = [292, 0, 8, 16];
    // Sky Base boss weapon ($430, $1A0): resting / firing
    public const SKY_WEAPON = [[302, 0, 16, 16], [320, 0, 16, 16]];
    // electric beam $46 (art loaded by the beam, $3EF3F): column segments, emitters; boss shot $47
    public const BEAM = [[338, 0, 16, 16], [356, 0, 16, 16]];
    public const BEAM_EMITTER = [374, 0, 16, 16];
    public const ELECTRIC_BALL = [[392, 0, 16, 16], [410, 0, 16, 16]];
    // Sonic animation $0E (frames 25-33): the teleport beam
    public const TELEPORT_BEAM = [[428, 0, 24, 32], [454, 0, 24, 32], [480, 0, 24, 32], [506, 0, 24, 32], [532, 0, 24, 32], [558, 0, 24, 32], [584, 0, 24, 32], [610, 0, 24, 32], [636, 0, 24, 32]];
    // end sequence $53: Robotnik's ship (_bf21), damaged with its flame (_bf33); boss art + S1_BossPalette
    public const SHIP = [[662, 0, 40, 48], [704, 0, 40, 48]];
    // Sky Base: passing cloud $30, moving spiked wall $39
    public const CLOUD = [746, 0, 32, 16];
    public const SPIKE_WALL = [780, 0, 16, 64];
    // special stages: moving bumper $21, flipper $4C at rest / swinging / up
    public const BUMPER = [798, 0, 32, 16];
    public const FLIPPER = [[832, 0, 32, 32], [866, 0, 32, 32], [900, 0, 32, 32]];
    // shield: Sonic's frame $18, drawn instead of Sonic every other frame (_5206)
    public const SHIELD = [934, 0, 24, 32];
    // Sonic waiting (animation $0D): frames $15, $16, $17
    public const SONIC_WAIT = [0x15 => [960, 0, 24, 32], 0x16 => [986, 0, 24, 32], 0x17 => [1012, 0, 24, 32]];
    // Sonic thrown up by a spring / a balance (animation $13, not rolling): frame $26
    public const SONIC_SPRING = [1146, 0, 24, 32];
    // Bridge falling piece $2E (_8481): tiles $70 $72 of the Bridge sprite art
    public const BRIDGE_PLANK = [1172, 0, 16, 16];
    // Labyrinth water slide (animation $11): frames $12, $24
    public const SONIC_SLIDE = [[1190, 0, 24, 32], [1216, 0, 24, 32]];
    // Sky Base bomb $32 (_ad0b): walking left 0-1, right 2-3, lit 4-5
    public const BOMB = [[1038, 0, 16, 16], [1056, 0, 16, 16], [1074, 0, 16, 16], [1092, 0, 16, 16], [1110, 0, 16, 16], [1128, 0, 16, 16]];
}
