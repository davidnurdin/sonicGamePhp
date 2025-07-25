<?php

namespace SonicGame\Entities\Physics;

use Evenement\EventEmitter;
use SonicGame\Entities\Entity;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;

class CollisionSystem extends EventEmitter
{
	private int $tileSize = 32;

	public function checkCollisions(Entity|Player $entity, Level $level, float $deltaTime)
	{
		$oldGrounded = $entity->isGrounded();
		$entityRect = $entity->getCollisionRect();

	//	dump($entity->isGrounded());
				
				
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

					//dump('Colision : '.$x.' '.$y);

					// Vérifie les collisions pixel par pixel
					$entityRect = $entity->getCollisionRect();

					$checkPixelCollision = $this->checkPixelCollision($entity, $level, $x, $y, $tileColisionData, $entityRect['x'], $entityRect['y']);
					if ($checkPixelCollision)
					{
						foreach ($checkPixelCollision as $pixelCollisionResolved)
						{
							if (isset($pixelCollisionResolved['bottom']))
							{
								// s'arrete a la premiere collision trouvée
								$entity->setGrounded(true,$pixelCollisionResolved['bottom']);
								return true;
							}
						}
					}

				}
			}
		}

		// Vérifie les limites du niveau
		$this->checkLevelBounds($entity, $level);

		//return true ;
		
		// --- STICK TO GROUND SI ON VIENT DE QUITTER LE SOL ---
		if (!$entity->isGrounded() && $oldGrounded)
		{


			$speedX = abs($entity->getVelocity()[0]);
			$snapMax = max($this->tileSize * 2, min(ceil($speedX * $deltaTime * 1.5), $this->tileSize));
			$entityRect = $entity->getCollisionRect();
			$feetY = $entityRect['y'] + $entityRect['height'];
			
			
			for ($dy = 0; $dy <= $snapMax; $dy++) {
				$tileX = floor($entityRect['x'] / $this->tileSize);
				$tileY = floor(($feetY + $dy) / $this->tileSize);
				$tileColisionData = $level->getTileColisionAt($tileX, $tileY);

				if ($tileColisionData)
				{
					
					$checkPixelCollision = $this->checkPixelCollision($entity, $level, $tileX, $tileY, $tileColisionData, $entityRect['x'], ($feetY + $dy)); // TODO 
					if ($checkPixelCollision)
					{
						foreach ($checkPixelCollision as $pixelCollisionResolved)
						{
							if (isset($pixelCollisionResolved['bottom']))
							{
								$entity->setGrounded(true, $pixelCollisionResolved['bottom']);
								return true ;
							}
						}
					}
				}
			}
		}

		return true;
	}

	private function checkPixelCollision(Entity|Player $entity, Level $level, int $tileX, int $tileY, array $tileColisionData,int $posX = null,int $posY = null) : array|bool
	{
		// TODO enlever (voir pk j'ai un décallage de 16px)
		//$entity->setX(690);
		//$entity->setY(0);
		//return ;

		

		$entityRect = $entity->getCollisionRect();

		if ($posX && $posY)
		{
			$entityRect['x'] = $posX;
			$entityRect['y'] = $posY;
		}

		// $entityRect['x'] = $entityRect['x'] + 16 ;

		$velocity = $entity->getVelocity();
		
		// Calcule la position relative de l'entité par rapport à la tile
		$entityTileX = $entityRect['x'] - ($tileX * $this->tileSize);
		$entityTileY = $entityRect['y'] - ($tileY * $this->tileSize);
		

		// Parcourt les pixels de l'entité qui peuvent entrer en collision avec la tile
		$entityStartX = max(0, (int)$entityTileX);
		$entityEndX = min($this->tileSize, (int)($entityTileX + $entityRect['width']));
		$entityStartY = max(0, (int)$entityTileY);
		$entityEndY = min($this->tileSize, (int)($entityTileY + $entityRect['height']));
		

		/*
		// Vérification prédictive : si l'entité monte, vérifier aussi les pixels au-dessus
		if ($velocity[1] < 0) { // Mouvement vers le haut
			$entityStartY = max(0, (int)($entityTileY + $velocity[1]));
		}
		
		// Vérification prédictive : si l'entité descend, vérifier aussi les pixels en dessous
		if ($velocity[1] > 0) { // Mouvement vers le bas
			$entityEndY = min($this->tileSize, (int)($entityTileY + $entityRect['height'] + $velocity[1]));
		}
		
		// Vérification prédictive : si l'entité va à gauche, vérifier aussi les pixels à gauche
		if ($velocity[0] < 0) { // Mouvement vers la gauche
			$entityStartX = max(0, (int)($entityTileX + $velocity[0]));
		}
		
		// Vérification prédictive : si l'entité va à droite, vérifier aussi les pixels à droite
		if ($velocity[0] > 0) { // Mouvement vers la droite
			$entityEndX = min($this->tileSize, (int)($entityTileX + $entityRect['width'] + $velocity[0]));
		}
			*/
		
		// Vérifie chaque pixel de l'entité
		for ($y = $entityStartY; $y < $entityEndY; $y++) {
			for ($x = $entityStartX; $x < $entityEndX; $x++) {
				// Vérifie si le pixel de la tile a une collision (comme dans Scene.php)
				if (isset($tileColisionData[$y][$x]) && $tileColisionData[$y][$x] == 1) {
					// Calcule la vraie tile où se trouve le pixel de collision
					$realTileX = $tileX + floor($x / $this->tileSize);
					$realTileY = $tileY + floor($y / $this->tileSize);
					$realPixelX = $x % $this->tileSize;
					$realPixelY = $y % $this->tileSize;
					
					// Émet un événement de collision pixel détectée
					$this->emit('pixelCollision', [
						'tileX' => $realTileX,
						'tileY' => $realTileY,
						'pixelX' => $realPixelX,
						'pixelY' => $realPixelY,
						'entityX' => $entityRect['x'],
						'entityY' => $entityRect['y'],
						'velocity' => $velocity
					]);
					
					// Collision détectée ! Détermine la direction et résout
					$directions = $this->getPixelCollisionDirection($entity, $realTileX, $realTileY, $realPixelX, $realPixelY, $velocity);
					// probleme ici quand on est grounded => renvoi "top" a la place de "bottom" ou les 4 a faire ?


					// il ne faudrais pas faire ça mais faire en fonction de la tuile en cours de test et de ses metas si elle est traversable par un coté (ou non) ou pleine.
					$pixelsCollisionResolved = [] ;

					foreach ($directions as $direction)
					{
						$pixelCollisionResolved = $this->resolvePixelCollision($entity, $realTileX, $realTileY, $realPixelX, $realPixelY, $direction);
						$pixelsCollisionResolved[] = $pixelCollisionResolved ;
					}

					
					return $pixelsCollisionResolved; // Une collision suffit
				}
			}
		}

		return false;
	}

	private function getPixelCollisionDirection(Entity|Player $entity, int $tileX, int $tileY, int $pixelX, int $pixelY, array $velocity): array
	{
		
		$toTest = [] ;
		// en fonction de la vélocity X
		if ($velocity[0] > 0)
			$toTest[] = 'right' ;
		if ($velocity[0] < 0)
			$toTest[] = 'left' ;
		if ($velocity[1] >= 0)
			$toTest[] = 'bottom' ;
		if ($velocity[1] < 0)
			$toTest[] = 'top' ;
			
		return $toTest ;

	}

	private function resolvePixelCollision(Entity|Player $entity, int $tileX, int $tileY, int $pixelX, int $pixelY, string $direction): array|bool
	{
		$entityRect = $entity->getCollisionRect();
		$velocity = $entity->getVelocity();

		
		switch ($direction) {
			case 'right':
			
				break;

			case 'left':
				
				break;

			case 'top':
				
				break;

			case 'bottom':
				// Collision par le bas (sol) 
				return [$direction => (($tileY-1) * $this->tileSize ) + $pixelY+1] ;

				//$entity->setY( (($tileY-1) * $this->tileSize ) + $pixelY+1 ); // on va forcer la position de l'entité a la position du sol
				//$entity->setGrounded(true);

				break;
		}

		return false ;

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
