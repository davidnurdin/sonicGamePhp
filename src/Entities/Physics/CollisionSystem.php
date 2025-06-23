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

		// Récupère les données de collision
		$tilesColision = $level->getTilesColision();

		// Vérifie les collisions avec les tiles
		for ($y = $startY; $y <= $endY; $y++) {
			for ($x = $startX; $x <= $endX; $x++) {
				// Calcul de l'index linéaire
				$index = $x + $y * $level->getMapWidth();
				
				// Vérifie si la tuile a des données de collision
				/*
				if (isset($tilesColision[$index])) {
					echo "DEBUG checkCollisions: tile ($x, $y) has collision data\n";
					
					$tileRect = [
						'x' => $x * $this->tileSize,
						'y' => $y * $this->tileSize,
						'width' => $this->tileSize,
						'height' => $this->tileSize
					];

					if ($this->checkRectCollision($entityRect, $tileRect)) {
						echo "DEBUG checkCollisions: collision detected at tile ($x, $y)\n";
						// Détermine la direction de collision
						$direction = $this->getCollisionDirection($entityRect, $tileRect);
						$this->resolveCollision($entity, $tileRect, $tilesColision[$index], $direction);
					}
				} else {
					echo "DEBUG checkCollisions: tile ($x, $y) has no collision data\n";
				}
					*/
			}
		}

		// Vérifie les limites du niveau
		// $this->checkLevelBounds($entity, $level);
	}

	private function checkRectCollision(array $rect1, array $rect2): bool
	{
		return $rect1['x'] < $rect2['x'] + $rect2['width'] &&
			$rect1['x'] + $rect1['width'] > $rect2['x'] &&
			$rect1['y'] < $rect2['y'] + $rect2['height'] &&
			$rect1['y'] + $rect1['height'] > $rect2['y'];
	}

	private function getCollisionDirection(array $entityRect, array $tileRect): string
	{
		// Calcule les overlaps
		$overlapX = min($entityRect['x'] + $entityRect['width'] - $tileRect['x'],
			$tileRect['x'] + $tileRect['width'] - $entityRect['x']);
		$overlapY = min($entityRect['y'] + $entityRect['height'] - $tileRect['y'],
			$tileRect['y'] + $tileRect['height'] - $entityRect['y']);

		// Retourne la direction selon le plus petit overlap
		if ($overlapX < $overlapY) {
			$direction = $entityRect['x'] < $tileRect['x'] ? 'right' : 'left';
		} else {
			$direction = $entityRect['y'] < $tileRect['y'] ? 'top' : 'bottom';
		}
		
		return $direction;
	}

	private function resolveCollision(Entity|Player $entity, array $tileRect, array $tileColision, string $direction)
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();

		// Pour l'instant, on considère qu'une tuile avec des données de collision est solide
		// TODO: Implémenter la logique pixel par pixel plus tard
		if (empty($tileColision)) {
			return; // Pas de collision si pas de données
		}

		// Résout la collision selon la direction
		switch ($direction) {
			case 'right':
				// Collision à droite
//				$entity->setX($tileRect['x'] - $entityRect['width']);
//				$entity->setVelocity(0, $velocity[1]);
				break;

			case 'left':
				// Collision à gauche
//				$entity->setX($tileRect['x'] + $tileRect['width']);
//				$entity->setVelocity(0, $velocity[1]);
				break;

			case 'top':
				// Collision par le haut (atterrissage)
				$entity->setY($tileRect['y'] - $entityRect['height']);
//				$entity->setVelocity($velocity[0], 0);
				$entity->setGrounded(true);

				// Change l'état si le joueur était en saut
				if ($entity->getState() === 'jump') {
					$entity->setState('idle');
				}
				break;

			case 'bottom':
				// Collision par le bas (plafond)
				$entity->setY($tileRect['y'] + $tileRect['height']);
//				$entity->setVelocity($velocity[0], 0);
				break;
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
