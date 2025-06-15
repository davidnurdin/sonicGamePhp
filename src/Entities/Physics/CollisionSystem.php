<?php

namespace SonicGame\Entities\Physics;

use SonicGame\Entities\Entity;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;

class CollisionSystem
{
	private int $tileSize = 32;

	public function checkCollisions(Entity|Player $entity, Level $level)
	{
		$entityRect = $entity->getCollisionRect();

		// Reset grounded state
		$entity->setGrounded(false);

		// Calcule les tiles autour de l'entité
		$startX = floor($entityRect['x'] / $this->tileSize);
		$endX = floor(($entityRect['x'] + $entityRect['width']) / $this->tileSize);
		$startY = floor($entityRect['y'] / $this->tileSize);
		$endY = floor(($entityRect['y'] + $entityRect['height']) / $this->tileSize);

		// Vérifie les collisions avec les tiles
		for ($y = $startY; $y <= $endY; $y++) {
			for ($x = $startX; $x <= $endX; $x++) {
				$tileValue = $level->getTile($x, $y);

				// Si la tile est solide (différent de 0 ou null)
				if ($tileValue !== null && $tileValue !== 0) {
					$tileRect = [
						'x' => $x * $this->tileSize,
						'y' => $y * $this->tileSize,
						'width' => $this->tileSize,
						'height' => $this->tileSize
					];

					if ($this->checkRectCollision($entityRect, $tileRect)) {
						$this->resolveCollision($entity, $tileRect);
					}
				}
			}
		}

		// Vérifie les limites du niveau
		$this->checkLevelBounds($entity, $level);
	}

	private function checkRectCollision(array $rect1, array $rect2): bool
	{
		return $rect1['x'] < $rect2['x'] + $rect2['width'] &&
			$rect1['x'] + $rect1['width'] > $rect2['x'] &&
			$rect1['y'] < $rect2['y'] + $rect2['height'] &&
			$rect1['y'] + $rect1['height'] > $rect2['y'];
	}

	private function resolveCollision(Entity|Player $entity, array $tileRect)
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();

		// Calcule les overlaps
		$overlapX = min($entityRect['x'] + $entityRect['width'] - $tileRect['x'],
			$tileRect['x'] + $tileRect['width'] - $entityRect['x']);
		$overlapY = min($entityRect['y'] + $entityRect['height'] - $tileRect['y'],
			$tileRect['y'] + $tileRect['height'] - $entityRect['y']);

		// Résout la collision selon le plus petit overlap
		if ($overlapX < $overlapY) {
			// Collision horizontale
			if ($entityRect['x'] < $tileRect['x']) {
				// Collision à droite
//				$entity->setX($tileRect['x'] - $entityRect['width']);
			} else {
				// Collision à gauche
//				$entity->setX($tileRect['x'] + $tileRect['width']);
			}
//			$entity->setVelocity(0, $velocity[1]);

		} else {
			// Collision verticale
			if ($entityRect['y'] < $tileRect['y']) {
				// Collision par le haut (atterrissage)
				$entity->setY($tileRect['y'] - $entityRect['height']);
//				$entity->setVelocity($velocity[0], 0);
				$entity->setGrounded(true);

				// Change l'état si le joueur était en saut
				if ($entity->getState() === 'jump') {
					$entity->setState('idle');
				}

			} else {
				// Collision par le bas (plafond)
				$entity->setY($tileRect['y'] + $tileRect['height']);
//				$entity->setVelocity($velocity[0], 0);
			}
		}
	}

	private function checkLevelBounds(Entity|Player $entity, Level $level)
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();

		// Limite gauche
		if ($entityRect['x'] < 0) {
//			$entity->setX(0);
//			$entity->setVelocity(0, $velocity[1]);
		}

		// Limite droite
		$maxX = $level->getMapWidth() * $this->tileSize - $entityRect['width'];
		if ($entityRect['x'] > $maxX) {
//			$entity->setX($maxX);
//			$entity->setVelocity(0, $velocity[1]);
		}

		// Limite bas (chute dans le vide)
		$maxY = $level->getMapHeight() * $this->tileSize;
		if ($entityRect['y'] > $maxY) {
			// Respawn ou game over
			$this->handleFallOffLevel($entity, $level);
		}
	}

	private function handleFallOffLevel(Entity|Player $entity, Level $level)
	{
		// Repositionne l'entité au point de spawn
//		$entity->setX($level->getCurrentPositionSoniceXinTile() * $this->tileSize);
//		$entity->setY($level->getCurrentPositionSoniceYinTile() * $this->tileSize);
//		$entity->setVelocity(0, 0);
//		$entity->setGrounded(false);
	}
}
