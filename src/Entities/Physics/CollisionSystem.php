<?php

namespace SonicGame\Entities\Physics;

use Evenement\EventEmitter;
use SonicGame\Entities\Entity;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;

class CollisionSystem extends EventEmitter
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

		// Vérifie les collisions avec les tiles pixel par pixel
		for ($y = $startY; $y <= $endY; $y++) {
			for ($x = $startX; $x <= $endX; $x++) {
				// Récupère les données de collision de la tile
				$tileColisionData = $level->getTileColisionAt($x, $y);
				
		
				if ($tileColisionData) {
					// Émet un événement pour les tiles avec collision
					$this->emit('collisionTile', [ [ 
						'tileX' => $x, 
						'tileY' => $y, 
						'tileValue' => $level->getTile($x, $y), 
						'collisionData' => $tileColisionData 
					] ]);
					
					// Vérifie les collisions pixel par pixel
					$this->checkPixelCollision($entity, $level, $x, $y, $tileColisionData);
				}
			}
		}

		// Vérifie les limites du niveau
		$this->checkLevelBounds($entity, $level);
	}

	private function checkPixelCollision(Entity|Player $entity, Level $level, int $tileX, int $tileY, array $tileColisionData)
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();
		
		// Calcule la position relative de l'entité par rapport à la tile
		$entityTileX = $entityRect['x'] - ($tileX * $this->tileSize);
		$entityTileY = $entityRect['y'] - ($tileY * $this->tileSize);
		
		// Parcourt les pixels de l'entité qui peuvent entrer en collision avec la tile
		$entityStartX = max(0, (int)$entityTileX);
		$entityEndX = min($this->tileSize, (int)($entityTileX + $entityRect['width']));
		$entityStartY = max(0, (int)$entityTileY);
		$entityEndY = min($this->tileSize, (int)($entityTileY + $entityRect['height']));
		
		// Vérifie chaque pixel de l'entité
		for ($y = $entityStartY; $y < $entityEndY; $y++) {
			for ($x = $entityStartX; $x < $entityEndX; $x++) {
				// Vérifie si le pixel de la tile a une collision (comme dans Scene.php)
				if (isset($tileColisionData[$y][$x]) && $tileColisionData[$y][$x] == 1) {
					// Émet un événement de collision pixel détectée
					$this->emit('pixelCollision', [
						'tileX' => $tileX,
						'tileY' => $tileY,
						'pixelX' => $x,
						'pixelY' => $y,
						'entityX' => $entityRect['x'],
						'entityY' => $entityRect['y'],
						'velocity' => $velocity
					]);
					
					// Collision détectée ! Détermine la direction et résout
					$direction = $this->getPixelCollisionDirection($entity, $tileX, $tileY, $x, $y, $velocity);
					$this->resolvePixelCollision($entity, $tileX, $tileY, $direction);
					return; // Une collision suffit
				}
			}
		}
	}

	private function getPixelCollisionDirection(Entity|Player $entity, int $tileX, int $tileY, int $pixelX, int $pixelY, array $velocity): string
	{
		$entityRect = $entity->getCollisionRect();
		
		// Calcule la position absolue du pixel de collision
		$collisionPixelX = $tileX * $this->tileSize + $pixelX;
		$collisionPixelY = $tileY * $this->tileSize + $pixelY;
		
		// Détermine la direction selon la vitesse et la position relative
		if (abs($velocity[0]) > abs($velocity[1])) {
			// Mouvement horizontal dominant
			return $velocity[0] > 0 ? 'right' : 'left';
		} else {
			// Mouvement vertical dominant
			return $velocity[1] > 0 ? 'bottom' : 'top';
		}
	}

	private function resolvePixelCollision(Entity|Player $entity, int $tileX, int $tileY, string $direction)
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();

		switch ($direction) {
			case 'right':
				// Collision à droite - désactivée pour le moment
				// $entity->setX($tileX * $this->tileSize - $entityRect['width']);
				// $entity->setVelocity(0, $velocity[1]);
				break;

			case 'left':
				// Collision à gauche - désactivée pour le moment
				// $entity->setX(($tileX + 1) * $this->tileSize);
				// $entity->setVelocity(0, $velocity[1]);
				break;

			case 'top':
				// Collision par le haut (atterrissage) - SEULE COLLISION ACTIVÉE
				$entity->setY($tileY * $this->tileSize - $entityRect['height']);
				$entity->setVelocity($velocity[0], 0);
				$entity->setGrounded(true);

				// Change l'état si le joueur était en saut
				if ($entity->getState() === 'jump') {
					$entity->setState('idle');
				}
				break;

			case 'bottom':
				// Collision par le bas (plafond) - désactivée pour le moment
				// $entity->setY(($tileY + 1) * $this->tileSize);
				// $entity->setVelocity($velocity[0], 0);
				break;
		}
	}

	private function checkLevelBounds(Entity|Player $entity, Level $level)
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();

		// Limite gauche
		if ($entityRect['x'] < 0) {
			$entity->setX(0);
			$entity->setVelocity(0, $velocity[1]);
		}

		// Limite droite
		$maxX = $level->getMapWidth() * $this->tileSize - $entityRect['width'];
		if ($entityRect['x'] > $maxX) {
			$entity->setX($maxX);
			$entity->setVelocity(0, $velocity[1]);
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
		$entity->setX($level->getCurrentPositionSoniceXinTile() * $this->tileSize);
		$entity->setY($level->getCurrentPositionSoniceYinTile() * $this->tileSize);
		$entity->setVelocity(0, 0);
		$entity->setGrounded(false);
	}
}
