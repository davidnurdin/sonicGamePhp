<?php

namespace SonicGame\InputManager;

class InputTouchpad
{
	private ?int $primaryFingerId = null;

	private array $fingerStartPositions = [];   // [fingerId => [x, y]]
	private array $heldFingers = [];             // [fingerId => true]
	private array $pressedFingers = [];          // [fingerId => true]
	private array $releasedFingers = [];         // [fingerId => true]

	private array $fingerActions = [];           // [fingerId => array d'actions]
	private array $heldActions = [];             // [actionName => true]
	private array $pressedActions = [];          // [actionName => true]
	private array $releasedActions = [];         // [actionName => true]

	private float $motionThresholdX = 0.1;
	private float $motionThresholdY = 0.2;

	private array $fingerDownTimestamps = [];    // [fingerId => float]
	private array $fingerPressOrder = [];        // ordre des doigts pressés (pile)

	private array $fingerHadSignificantMotion = [];  // [fingerId => bool]

	public function __construct()
	{
	}

	public function setMotionThresholdX(float $threshold): void
	{
		$this->motionThresholdX = $threshold;
	}

	public function setMotionThresholdY(float $threshold): void
	{
		$this->motionThresholdY = $threshold;
	}

	public function handle($event): void
	{
		$type = $event->type;
		$fingerId = $event->tfinger->fingerId;
		$x = $event->tfinger->x;
		$y = $event->tfinger->y;
		$now = microtime(true);

		// Reset des actions transitoires
		$this->pressedActions = [];
		$this->releasedActions = [];
		$this->pressedFingers = [];
		$this->releasedFingers = [];

		switch ($type) {

			case \SDL_FINGERDOWN:
				if (!isset($this->heldFingers[$fingerId])) {
					$this->heldFingers[$fingerId] = true;
					$this->pressedFingers[$fingerId] = true;
					$this->fingerStartPositions[$fingerId] = [$x, $y];
					$this->fingerDownTimestamps[$fingerId] = $now;
					$this->fingerPressOrder[] = $fingerId;
					$this->fingerHadSignificantMotion[$fingerId] = false;

					if ($this->primaryFingerId === null) {
						$this->primaryFingerId = $fingerId;
					}
				}
				break;

			case \SDL_FINGERMOTION:
				if (!isset($this->heldFingers[$fingerId])) {
					break;
				}

				[$startX, $startY] = $this->fingerStartPositions[$fingerId];
				$dx = $x - $startX;
				$dy = $y - $startY;

				$absDx = abs($dx);
				$absDy = abs($dy);

				if ($absDx >= $this->motionThresholdX || $absDy >= $this->motionThresholdY) {
					$this->fingerHadSignificantMotion[$fingerId] = true;
				}

				if (count($this->heldFingers) > 1 && $fingerId === $this->primaryFingerId) {
					break;
				}

				if ($fingerId === $this->primaryFingerId) {
					$actions = $this->fingerActions[$fingerId] ?? [];

					$newActions = [];

					if ($absDx >= $this->motionThresholdX) {
						$newActions[] = $dx < 0 ? 'left' : 'right';
					}

					if ($absDy >= $this->motionThresholdY) {
						$newActions[] = $dy < 0 ? 'up' : 'down';
					}

					if ($absDx < $this->motionThresholdX && $absDy < $this->motionThresholdY) {
						if (!empty($actions)) {
							foreach ($actions as $actionToRemove) {
								unset($this->heldActions[$actionToRemove]);
							}
							$this->fingerActions[$fingerId] = [];
						}
					} else {
						foreach ($newActions as $a) {
							if (!in_array($a, $actions, true)) {
								$this->pressedActions[$a] = true;
							}
						}
						$this->fingerActions[$fingerId] = $newActions;
					}
				} else {
					if ($dy >= $this->motionThresholdY) {
						$this->pressedActions['roll'] = true;
						$this->fingerActions[$fingerId] = ['roll'];
					}
				}
				break;

			case \SDL_FINGERUP:
				if (isset($this->heldFingers[$fingerId])) {
					$this->releasedFingers[$fingerId] = true;

					$actions = $this->fingerActions[$fingerId] ?? [];

					// Détection JUMP : tap sans mouvement et sans direction
					$hadMotion = $this->fingerHadSignificantMotion[$fingerId] ?? false;
					$hasNoDirection = empty($actions);
					if (!$hadMotion && $hasNoDirection) {
						$this->pressedActions['jump'] = true;
					}

					foreach ($actions as $a) {
						$this->releasedActions[$a] = true;
					}

					unset($this->heldFingers[$fingerId]);
					unset($this->fingerDownTimestamps[$fingerId]);
					unset($this->fingerStartPositions[$fingerId]);
					unset($this->fingerActions[$fingerId]);
					unset($this->fingerHadSignificantMotion[$fingerId]);

					if ($fingerId === $this->primaryFingerId) {
						$this->primaryFingerId = null;
						foreach (array_keys($this->heldFingers) as $fid) {
							$this->primaryFingerId = $fid;
							break;
						}
					}

					$index = array_search($fingerId, $this->fingerPressOrder);
					if ($index !== false) {
						unset($this->fingerPressOrder[$index]);
						$this->fingerPressOrder = array_values($this->fingerPressOrder);
					}
				}
				break;
		}

		$this->heldActions = [];
		foreach ($this->fingerActions as $actions) {
			foreach ($actions as $a) {
				$this->heldActions[$a] = true;
			}
		}

		if (isset($this->pressedActions['roll'])) {
			$this->heldActions['roll'] = true;
		}


	}

	public function isActionPressed(string $action): bool
	{
		return $this->pressedActions[$action] ?? false;
	}

	public function isActionHeld(string $action): bool
	{
		return $this->heldActions[$action] ?? false;
	}

	public function isActionReleased(string $action): bool
	{
		return $this->releasedActions[$action] ?? false;
	}

    public function isOneActionHelded(): bool
    {
        return count($this->heldActions) > 0;
    }

	public function resetTransientStates(): void
	{
		$this->pressedActions = [];
		$this->releasedActions = [];
		$this->pressedFingers = [];
		$this->releasedFingers = [];
	}

	public function haveOneFingerPressed(): bool
	{
		return count($this->pressedFingers) > 0;
	}

	public function haveOneFingerHelded(): bool
	{
		return count($this->heldFingers) > 0;
	}

	public function getCurrentFingersHeld(): array
	{
		return array_keys($this->heldFingers);
	}

	public function getLastFingerPressed(): ?int
	{
		return count($this->fingerPressOrder) > 0
			? end($this->fingerPressOrder)
			: null;
	}

	public function getActionsHelded()
	{
		return array_keys($this->heldActions);
	}
}
