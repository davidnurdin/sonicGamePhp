<?php

namespace SonicGame\Entities\Physics;

use SonicGame\SoundManager\SoundManager;

use Evenement\EventEmitter;
use SonicGame\Entities\Entity;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;

class CollisionSystem extends EventEmitter
{
	private int $tileSize = 32;

	private float $springForce = -1100.0;
	private float $horizontalSpringForce = 600.0;
	private float $slopeFactor = 500.0;
	private float $bumperBounceForce = 700.0;

	// Original speeds are px/frame at 60Hz. Vertical launches keep the original heights in pixels:
	// original gravity is $38/256 px/frame² (787.5 px/s²) vs 1400 here, so v = v60 * sqrt(1400 / 787.5) = v * 80.
	private const VERTICAL_SCALE = 80.0;
	private const FRAME_SPEED = 60.0;

	private const B_SPIKES = 1;
	private const B_RAMP = 2;
	private const B_SPRING_LEFT = 3;
	private const B_SPRING_UP_RIGHT_HALF = 4;
	private const B_SPRING_RIGHT = 5;
	private const B_CONVEYOR_LEFT = 6;
	private const B_CONVEYOR_RIGHT = 7;
	private const B_SPRING_UP_CENTER = 9;
	private const B_CURRENT_LEFT = 12;
	private const B_SLIDE_RIGHT_5 = 13;
	private const B_SLIDE_RIGHT_6 = 14;
	private const B_SLIDE_LEFT_5 = 15;
	private const B_SLIDE_LEFT_6 = 16;
	private const B_BUMPER_VERTICAL = 17;
	private const B_SPRING_10 = 18;
	private const B_SPRING_12 = 19;
	private const B_SPRING_14 = 20;
	private const B_BUMPER_HORIZONTAL = 21;
	private const B_BUMPER_VERTICAL_2 = 22;
	private const B_ELECTRIC = 23;

	private static array $blockBehaviour = [];
	private array $behaviour = [];
	private float $bumperCooldown = 0;
	/** Time (microtime) each sound effect was last emitted, so blocks acting every frame don't spam it. */
	private array $sfxClock = [];
	private int $currentLevel = 0;
	/** @var callable|null fn(array $rect, float $vy, string $state): ?array{y: float, dx: float} */
	private $objectGroundProvider = null;

	public function setObjectGroundProvider(?callable $provider): void
	{
		$this->objectGroundProvider = $provider;
	}

	/** @var callable|null fn(): ?float, the Labyrinth water line (object $40) */
	private $waterLineProvider = null;

	public function setWaterLineProvider(?callable $provider): void
	{
		$this->waterLineProvider = $provider;
	}

	private function updateLevelConfig(Level $level): void
	{
		$lvl = $level->getLevel();
		if ($lvl === $this->currentLevel) return;
		$this->currentLevel = $lvl;

		if (empty(self::$blockBehaviour)) {
			self::$blockBehaviour = require __DIR__ . '/../../../assets/levels/blockBehaviour.data.php';
		}
		$this->behaviour = self::$blockBehaviour[$level->getSolidityIndex()] ?? [];

	}

	public function checkCollisions(Entity|Player $entity, Level $level, float $deltaTime)
	{
		$this->updateLevelConfig($level);
		$oldGrounded = $entity->isGrounded();
		$velocity = $entity->getVelocity();

		$entity->setGrounded(false);

		// --- Ground detection (when falling or standing) ---
		$onObject = false;
		if ($velocity[1] >= 0) {
			$groundResult = $this->detectGround($entity, $level);
			$objectGround = $this->objectGroundProvider !== null
				? ($this->objectGroundProvider)($entity->getCollisionRect(), $velocity[1], $entity->getState())
				: null;
			if ($objectGround !== null && ($groundResult === null || $objectGround['y'] < $groundResult['entityY'] || $objectGround['force'])) {
				$groundResult = ['entityY' => $objectGround['y']];
				$entity->setX($entity->getCollisionRect()['x'] + $objectGround['dx']);
				$onObject = true;
			}
			if ($groundResult !== null) {
				$entity->setY($groundResult['entityY']);
				$entity->setGrounded(true, $groundResult['entityY']);
				$entity->setVelocity($velocity[0], 0);
			}
		}

		// --- Ceiling detection (when moving up) ---
		if ($velocity[1] < 0) {
			$ceilY = $this->detectCeiling($entity, $level);
			if ($ceilY !== null) {
				$entity->setY($ceilY);
				$vel = $entity->getVelocity();
				$entity->setVelocity($vel[0], 0);
			}
		}

		// --- Wall detection ---
		$vel = $entity->getVelocity();
		if ($vel[0] > 0) {
			$wallX = $this->detectWallRight($entity, $level);
			if ($wallX !== null) {
				$entity->setX($wallX);
				$entity->setVelocity(0, $entity->getVelocity()[1]);
			}
		} elseif ($vel[0] < 0) {
			$wallX = $this->detectWallLeft($entity, $level);
			if ($wallX !== null) {
				$entity->setX($wallX);
				$entity->setVelocity(0, $entity->getVelocity()[1]);
			}
		}

		// --- Block behaviours from the ROM table (springs, spikes, ramps, conveyors...) ---
		$this->applyBlockBehaviour($entity, $level, $deltaTime);

		// --- Slope acceleration (when grounded) ---
		if ($entity->isGrounded() && !$onObject) {
			$slope = $this->calculateGroundSlope($entity, $level);
			if (abs($slope) > 0.05) {
				$slopeAccel = $this->slopeFactor * $slope;
				$v = $entity->getVelocity();
				$entity->setVelocity($v[0] + $slopeAccel * $deltaTime, $v[1]);
			}
		}

		// --- Snap to ground (was grounded, walked off slope/edge) ---
		$currentVel = $entity->getVelocity();
		if (!$entity->isGrounded() && $oldGrounded && $currentVel[1] >= 0) {
			$snapResult = $this->snapToGround($entity, $level, $deltaTime);
			if ($snapResult !== null) {
				$entity->setY($snapResult);
				$entity->setGrounded(true, $snapResult);
				$v = $entity->getVelocity();
				$entity->setVelocity($v[0], 0);
			}
		}

		// --- Water detection ---
		$this->detectWater($entity, $level);

		$this->checkLevelBounds($entity, $level);
	}

	private function calculateGroundSlope(Entity|Player $entity, Level $level): float
	{
		$rect = $entity->getCollisionRect();
		$feetY = $rect['y'] + $rect['height'] + 2;
		$centerX = (int)floor($rect['x'] + $rect['width'] / 2);

		$sampleDist = $this->tileSize + 8;
		$leftX = $centerX - $sampleDist;
		$rightX = $centerX + $sampleDist;

		$leftSurfaceY = $this->getSurfaceYAt($leftX, $feetY, $level, 3);
		$rightSurfaceY = $this->getSurfaceYAt($rightX, $feetY, $level, 3);

		if ($leftSurfaceY === null || $rightSurfaceY === null) return 0.0;

		$dx = $rightX - $leftX;
		if ($dx == 0) return 0.0;

		return ($rightSurfaceY - $leftSurfaceY) / $dx;
	}

	private function getSurfaceYAt(int $x, float $feetY, Level $level, int $searchRange = 1): ?float
	{
		$tileX = (int)floor($x / $this->tileSize);
		$pixelX = $x - ($tileX * $this->tileSize);
		$tileYAtFeet = (int)floor($feetY / $this->tileSize);

		$best = null;
		$bestDist = PHP_FLOAT_MAX;

		for ($ty = max(0, $tileYAtFeet - $searchRange); $ty <= $tileYAtFeet + $searchRange; $ty++) {
			$surfaceY = $this->findSurfaceYForGround($level, $tileX, $ty, $pixelX);
			if ($surfaceY === null) continue;

			$dist = abs($feetY - $surfaceY);
			if ($dist < $bestDist) {
				$bestDist = $dist;
				$best = $surfaceY;
			}
		}

		return $best;
	}

	private function detectWater(Entity|Player $entity, Level $level): void
	{
		$rect = $entity->getCollisionRect();
		// the original looks at one point only, the centre of Sonic's 24x32 object (X + $0C, Y + $10)
		$centerX = (int)floor($entity->getX() + 0x0C);
		$centerY = (int)floor($rect['y'] + $rect['height'] - 16);
		$tile = $level->getTile((int)floor($centerX / $this->tileSize), (int)floor($centerY / $this->tileSize));

		// Labyrinth: underwater once the top of Sonic is below the water line (_50e8). Elsewhere the block
		// behaviour 8 (_55a8) sets the water flag and only behaviour 0 (_54bc) clears it, the other
		// behaviours leave it as it is.
		$waterLine = $this->waterLineProvider !== null ? ($this->waterLineProvider)() : null;
		if ($waterLine !== null) {
			$inWater = $rect['y'] > $waterLine;
		} else {
			$b = $tile !== null ? ($this->behaviour[$tile] ?? 0) : 0;
			$wasInWater = method_exists($entity, 'isInWater') && $entity->isInWater();
			$inWater = $b === 8 || ($b !== 0 && $wasInWater);
		}

		if (method_exists($entity, 'setInWater')) {
			if ($inWater && !$entity->isInWater()) {
				$this->sfx(SoundManager::SFX_SPLASH);
			}
			$entity->setInWater($inWater);
		}
	}

	public function getBlockBehaviourAt(Level $level, float $x, float $y): int
	{
		$tile = $level->getTile((int)floor($x / $this->tileSize), (int)floor($y / $this->tileSize));
		if ($tile === null) return 0;
		return $this->behaviour[$tile] ?? 0;
	}

	private function applyBlockBehaviour(Entity|Player $entity, Level $level, float $deltaTime): void
	{
		if ($this->bumperCooldown > 0) {
			$this->bumperCooldown -= $deltaTime;
		}
		if (empty($this->behaviour)) return;

		$rect = $entity->getCollisionRect();
		$probeX = $rect['x'] + $rect['width'] / 2;
		$probeY = $rect['y'] + $rect['height'] - 1;
		$b = $this->getBlockBehaviourAt($level, $probeX, $probeY);
		$headBehaviour = $this->getBlockBehaviourAt($level, $probeX, $rect['y'] + 1);
		if ($headBehaviour === self::B_SPIKES || $headBehaviour === self::B_ELECTRIC) {
			$b = $headBehaviour;
		}
		if ($b === 0 || $b >= 0x1C) return;

		$xInBlock = ((int)floor($probeX)) & 31;
		[$vx, $vy] = $entity->getVelocity();
		$grounded = $entity->isGrounded();

		switch ($b) {
			case self::B_SPIKES:
			case self::B_ELECTRIC:
				$this->emit('playerHazard', [$entity]);
				break;

			case self::B_RAMP:
				if ($xInBlock >= 26 && $vx >= 3 * self::FRAME_SPEED) {
					$this->launch($entity, $vx, -2 * ($vx / self::FRAME_SPEED) * self::VERTICAL_SCALE, SoundManager::SFX_LAUNCH);
				}
				break;

			case self::B_SPRING_LEFT:
				if ($xInBlock >= 16) {
					$entity->setVelocity(-$this->horizontalSpringForce, $vy);
					$entity->setFacing('left');
					$this->sfx(SoundManager::SFX_SPRING);
				}
				break;

			case self::B_SPRING_RIGHT:
				if ($xInBlock < 16) {
					$entity->setVelocity($this->horizontalSpringForce, $vy);
					$entity->setFacing('right');
					$this->sfx(SoundManager::SFX_SPRING);
				}
				break;

			case self::B_SPRING_UP_RIGHT_HALF:
				if ($xInBlock >= 16 && $grounded) {
					$this->launch($entity, $vx, -12 * self::VERTICAL_SCALE);
				}
				break;

			case self::B_SPRING_UP_CENTER:
				if ($xInBlock >= 8 && $xInBlock < 24 && $grounded) {
					$this->launch($entity, $vx, -12 * self::VERTICAL_SCALE);
				}
				break;

			case self::B_CONVEYOR_LEFT:
				if ($grounded) $entity->setX($entity->getCollisionRect()['x'] - 1.5 * self::FRAME_SPEED * $deltaTime);
				break;

			case self::B_CONVEYOR_RIGHT:
				if ($grounded) $entity->setX($entity->getCollisionRect()['x'] + 2 * self::FRAME_SPEED * $deltaTime);
				break;

			case self::B_CURRENT_LEFT:
				$entity->setVelocity($vx - 8 / 256 * self::FRAME_SPEED * self::FRAME_SPEED * $deltaTime, $vy);
				break;

			case self::B_SLIDE_RIGHT_5:
			case self::B_SLIDE_RIGHT_6:
			case self::B_SLIDE_LEFT_5:
			case self::B_SLIDE_LEFT_6:
				$speed = [self::B_SLIDE_RIGHT_5 => 5, self::B_SLIDE_RIGHT_6 => 6, self::B_SLIDE_LEFT_5 => -5, self::B_SLIDE_LEFT_6 => -6][$b];
				// _567c..: X speed set, Y speed 4 px/frame, no control, neither jumping nor rolling, animation $11
				$entity->setVelocity($speed * self::FRAME_SPEED, 4 * self::VERTICAL_SCALE);
				if ($entity instanceof Player) {
					if ($entity->getState() === 'roll' || $entity->getState() === 'jump') $entity->setState('walk');
					$entity->setSliding($speed < 0);
				}
				break;

			case self::B_SPRING_10:
				$this->launch($entity, $vx, -10 * self::VERTICAL_SCALE);
				break;
			case self::B_SPRING_12:
				$this->launch($entity, $vx, -12 * self::VERTICAL_SCALE);
				break;
			case self::B_SPRING_14:
				$this->launch($entity, $vx, -14 * self::VERTICAL_SCALE);
				break;

			case self::B_BUMPER_VERTICAL:
			case self::B_BUMPER_VERTICAL_2:
				if ($this->bumperCooldown > 0) break;
				$this->bumperCooldown = 8 / self::FRAME_SPEED;
				$towardsCenter = ($xInBlock < 16) === ($vx >= 0);
				$newVx = $towardsCenter ? -$vx * 1.5 : $vx * 1.5;
				$newVy = -$vy;
				if ($newVy < 0) $newVy -= 0.22 * self::VERTICAL_SCALE;
				$entity->setVelocity($newVx, max($newVy, -$this->bumperBounceForce * 1.5));
				$entity->setGrounded(false);
				$this->sfx(SoundManager::SFX_BUMPER);
				// _57cd -> _57be: the big bumpers flash (background colour 14 turns white)
				if ($b === self::B_BUMPER_VERTICAL_2) $this->emit('paletteFlash', []);
				break;

			case self::B_BUMPER_HORIZONTAL:
				if ($this->bumperCooldown > 0) break;
				$this->bumperCooldown = 4 / self::FRAME_SPEED;
				$entity->setVelocity(-$vx + ($vx < 0 ? self::FRAME_SPEED : -self::FRAME_SPEED), $vy);
				$this->sfx(SoundManager::SFX_BUMPER);
				// _5791 -> _57be
				$this->emit('paletteFlash', []);
				break;
		}
	}

	private function launch(Entity|Player $entity, float $vx, float $vy, int $sfx = SoundManager::SFX_SPRING): void
	{
		$this->sfx($sfx);
		$entity->setGrounded(false);
		$entity->setVelocity($vx, $vy);
		if (method_exists($entity, 'setState')) {
			$entity->setState('jump');
		}
		if ($vy < 0 && method_exists($entity, 'setSprung')) {
			$entity->setSprung();
		}
	}

	private function sfx(int $index): void
	{
		$now = microtime(true);
		if ($now - ($this->sfxClock[$index] ?? 0) < 0.25) return;
		$this->sfxClock[$index] = $now;
		$this->emit('sfx', [$index]);
	}

	private function detectGround(Entity|Player $entity, Level $level): ?array
	{
		$rect = $entity->getCollisionRect();
		$feetY = $rect['y'] + $rect['height'];

		$sensorXs = [
			(int)floor($rect['x']) + 4,
			(int)floor($rect['x'] + $rect['width'] / 2),
			(int)floor($rect['x'] + $rect['width']) - 4,
		];

		$bestEntityY = null;

		foreach ($sensorXs as $sx) {
			$tileX = (int)floor($sx / $this->tileSize);
			$pixelX = $sx - ($tileX * $this->tileSize);

			$tileYAtFeet = (int)floor($feetY / $this->tileSize);

			for ($ty = max(0, $tileYAtFeet - 1); $ty <= $tileYAtFeet + 1; $ty++) {
				$surfaceY = $this->findSurfaceYForGround($level, $tileX, $ty, $pixelX);
				if ($surfaceY === null) continue;

				$penetration = $feetY - $surfaceY;

				if ($penetration >= 0 && $penetration <= $this->tileSize) {
					$entityY = $surfaceY - $rect['height'];

					$this->emit('collisionTile', [[
						'tileX' => $tileX,
						'tileY' => $ty,
						'tileValue' => $level->getTile($tileX, $ty),
						'collisionData' => $level->getTileColisionAt($tileX, $ty)
					]]);

					if ($bestEntityY === null || $entityY < $bestEntityY) {
						$bestEntityY = $entityY;
					}
				}
			}
		}

		if ($bestEntityY === null) return null;
		return ['entityY' => $bestEntityY];
	}

	private function findSurfaceYForGround(Level $level, int $tileX, int $tileY, int $pixelX): ?float
	{
		$colData = $level->getTileColisionAt($tileX, $tileY);
		if (!$colData) return null;

		for ($row = 0; $row < $this->tileSize; $row++) {
			if (isset($colData[$row][$pixelX]) && $colData[$row][$pixelX] == 1) {
				return (float)($tileY * $this->tileSize + $row);
			}
		}

		return null;
	}

	private function detectCeiling(Entity|Player $entity, Level $level): ?float
	{
		$rect = $entity->getCollisionRect();
		$headY = (int)floor($rect['y']);

		$sensorXs = [
			(int)floor($rect['x']) + 4,
			(int)floor($rect['x'] + $rect['width'] / 2),
			(int)floor($rect['x'] + $rect['width']) - 4,
		];

		$bestEntityY = null;

		foreach ($sensorXs as $sx) {
			$tileX = (int)floor($sx / $this->tileSize);
			$pixelX = $sx - ($tileX * $this->tileSize);

			$tileYAtHead = (int)floor($headY / $this->tileSize);

			for ($ty = max(0, $tileYAtHead - 1); $ty <= $tileYAtHead; $ty++) {
				$colData = $level->getTileColisionAt($tileX, $ty);
				if (!$colData) continue;

				$colWay = $level->getTileColisionWayAt($tileX, $ty);
				if ($colWay !== null && empty($colWay['top'])) continue;

				for ($row = $this->tileSize - 1; $row >= 0; $row--) {
					if (isset($colData[$row][$pixelX]) && $colData[$row][$pixelX] == 1) {
						$ceilingBottomY = (float)($ty * $this->tileSize + $row + 1);

						if ($headY < $ceilingBottomY && $ceilingBottomY - $headY <= $this->tileSize) {
							if ($bestEntityY === null || $ceilingBottomY > $bestEntityY) {
								$bestEntityY = $ceilingBottomY;
							}
						}
						break;
					}
				}
			}
		}

		return $bestEntityY;
	}

	private function detectWallRight(Entity|Player $entity, Level $level): ?float
	{
		$rect = $entity->getCollisionRect();
		$rightEdge = (int)floor($rect['x'] + $rect['width']);

		$sensorYs = [
			(int)floor($rect['y'] + $rect['height'] * 0.25),
			(int)floor($rect['y'] + $rect['height'] * 0.5),
		];

		foreach ($sensorYs as $sy) {
			$tileX = (int)floor($rightEdge / $this->tileSize);
			$pixelX = $rightEdge - ($tileX * $this->tileSize);
			$tileY = (int)floor($sy / $this->tileSize);
			$pixelY = $sy - ($tileY * $this->tileSize);

			$colData = $level->getTileColisionAt($tileX, $tileY);
			if (!$colData) continue;

			$colWay = $level->getTileColisionWayAt($tileX, $tileY);
			if ($colWay !== null && empty($colWay['right'])) continue;

			if (isset($colData[$pixelY][$pixelX]) && $colData[$pixelY][$pixelX] == 1) {
				for ($px = 0; $px < $this->tileSize; $px++) {
					if (isset($colData[$pixelY][$px]) && $colData[$pixelY][$px] == 1) {
						$wallWorldX = (float)($tileX * $this->tileSize + $px);
						return $wallWorldX - $rect['width'];
					}
				}
			}
		}

		return null;
	}

	private function detectWallLeft(Entity|Player $entity, Level $level): ?float
	{
		$rect = $entity->getCollisionRect();
		$leftEdge = (int)floor($rect['x']);

		$sensorYs = [
			(int)floor($rect['y'] + $rect['height'] * 0.25),
			(int)floor($rect['y'] + $rect['height'] * 0.5),
		];

		foreach ($sensorYs as $sy) {
			$tileX = (int)floor($leftEdge / $this->tileSize);
			$pixelX = $leftEdge - ($tileX * $this->tileSize);
			$tileY = (int)floor($sy / $this->tileSize);
			$pixelY = $sy - ($tileY * $this->tileSize);

			if ($pixelX < 0) { $tileX--; $pixelX += $this->tileSize; }

			$colData = $level->getTileColisionAt($tileX, $tileY);
			if (!$colData) continue;

			$colWay = $level->getTileColisionWayAt($tileX, $tileY);
			if ($colWay !== null && empty($colWay['left'])) continue;

			if (isset($colData[$pixelY][$pixelX]) && $colData[$pixelY][$pixelX] == 1) {
				for ($px = $this->tileSize - 1; $px >= 0; $px--) {
					if (isset($colData[$pixelY][$px]) && $colData[$pixelY][$px] == 1) {
						$wallWorldX = (float)($tileX * $this->tileSize + $px + 1);
						return $wallWorldX;
					}
				}
			}
		}

		return null;
	}

	private function snapToGround(Entity|Player $entity, Level $level, float $deltaTime): ?float
	{
		$rect = $entity->getCollisionRect();
		$feetY = $rect['y'] + $rect['height'];
		$speedX = abs($entity->getVelocity()[0]);
		$snapDistance = max($this->tileSize, (int)ceil($speedX * $deltaTime * 2));
		$snapDistance = min($snapDistance, $this->tileSize * 2);

		$sensorXs = [
			(int)floor($rect['x']) + 4,
			(int)floor($rect['x'] + $rect['width'] / 2),
			(int)floor($rect['x'] + $rect['width']) - 4,
		];

		$bestEntityY = null;

		foreach ($sensorXs as $sx) {
			$tileX = (int)floor($sx / $this->tileSize);
			$pixelX = $sx - ($tileX * $this->tileSize);

			for ($dy = 0; $dy <= $snapDistance; $dy++) {
				$checkY = (int)floor($feetY) + $dy;
				$tileY = (int)floor($checkY / $this->tileSize);

				$surfaceY = $this->findSurfaceYForGround($level, $tileX, $tileY, $pixelX);
				if ($surfaceY === null) continue;

				if ($checkY >= $surfaceY) {
					$entityY = $surfaceY - $rect['height'];
					if ($bestEntityY === null || $entityY < $bestEntityY) {
						$bestEntityY = $entityY;
					}
					break;
				}
			}
		}

		return $bestEntityY;
	}

	private function checkLevelBounds(Entity|Player $entity, Level $level)
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();

		if ($entityRect['x'] < 0) {
			$entity->setX(0);
			$entity->setVelocity(0, $velocity[1]);
		}

		$maxX = $level->getMapWidth() * $this->tileSize - $entityRect['width'];
		if ($entityRect['x'] > $maxX) {
			$entity->setX($maxX);
			$entity->setVelocity(0, $velocity[1]);
		}

		$maxY = $level->getMapHeight() * $this->tileSize;
		if ($entityRect['y'] > $maxY) {
			$this->handleFallOffLevel($entity, $level);
		}
	}

	private function handleFallOffLevel(Entity|Player $entity, Level $level)
	{
		if ($this->listeners('playerFell')) {
			$this->emit('playerFell', [$entity]);
			return;
		}
		$entity->setX($level->getCurrentPositionSoniceXinTile() * $this->tileSize);
		$entity->setY($level->getCurrentPositionSoniceYinTile() * $this->tileSize);
		$entity->setVelocity(0, 0);
		$entity->setGrounded(false);
	}
}
