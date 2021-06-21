<?php

namespace App\Banks\Base;

use App\Exceptions\StageException;
use App\Interfaces\StagesInterface;
use App\Models\Banks\BankGroup;
use App\Models\StatementBank;
use App\Traits\StagesConditionsTrait;
use App\Traits\StagesMethodsTrait;
use Illuminate\Support\Facades\DB;

class StagesHandler implements StagesInterface
{
    use StagesConditionsTrait, StagesMethodsTrait;

    /** Статусы, в которых можно редактировать шаг 1,2 клиентом */
    public const STAGES_FOR_CLIENT_EDIT = [
        'new',
        'onClientRevision',
        'onClientRevisionAfterBank'
    ];

    /** Статусы, в которых можно редактировать шаг 1,2 модератором */
    public const STAGES_FOR_MODER_EDIT = [
        'newOnModer',
        'fromClientRevision',
        'beforeClientRevisionAfterBank',
        'onModerAfterBank'
    ];

    /** @var StatementBank */
    protected StatementBank $statement;
    /** @var BankGroup */
    private BankGroup $group;
    /** @var array[] */
    private array $stages;

    /**
     * StagesHandler constructor.
     * @param StatementBank $childStmt
     * @param BankGroup $group
     */
    public function __construct(StatementBank $childStmt, BankGroup $group)
    {
        $byId = [];
        $byName = [];
        $this->role = (auth()->user()) ? auth()->user()->role->name : 'auto';

        if ($this->role == 'agent') {
            $this->role = 'client';
        }

        $this->statement = $childStmt;
        $this->group = $group;

        $sql = 'SELECT 
                    bgs.stage_id as id, 
                    bgs.name, 
                    bgs.side, 
                    bgs.priority, 
                    bgs.is_hidden_substage, 
                    s.role, 
                    sl.text 
                FROM bank_groups_stages as bgs 
                JOIN stages as s ON s.stage_id = bgs.stage_id AND s.group_id = bgs.group_id 
                LEFT JOIN stages_lang as sl ON sl.id = s.lang
                WHERE bgs.group_id = :group_id';

        $sqlResult = DB::select(DB::raw($sql), ['group_id' => $this->group->id]);

        foreach ($sqlResult as $stage) {
            if (!isset($byId[$stage['id']])) {
                $stageInfo = [
                    'id'                 => $stage['id'],
                    'name'               => $stage['name'],
                    'priority'           => $stage['side'],
                    'is_hidden_substage' => $stage,
                    $stage['role']       => $stage['text'],
                ];

                $byId[$stage['id']] = $stageInfo;
                $byName[$stage['name']] = $stageInfo;
            } else {
                $byId[$stage['id']][$stage['role']] = $stage['text'];
                $byName[$stage['name']][$stage['role']] = $stage['text'];
            }
        }

        $this->stages = ['id' => $byId, 'name' => $byName];
    }

    /**
     * Возвращает экземпляр модели заявки
     *
     * @return StatementBank
     */
    public function getChildStmtInstance(): StatementBank
    {
        return $this->statement;
    }

    /**
     * Заявка находится в успешной завершенной стадии.
     *
     * @return bool
     */
    public function isSuccessfulFinishStage(): bool
    {
        return $this->is($this->finishStage);
    }

    /**
     * Получение имени финального статуса для заявки.
     *
     * @return string
     */
    public function getSuccessfullFinishStage(): string
    {
        return $this->finishStage;
    }

    /**
     * Заявка находится в любой завершенной стадии.
     *
     * @return bool
     */
    public function isAnyFinishStage(): bool
    {
        return $this->is([$this->finishStage, 'canceled', 'denied', 'deniedByBank']);
    }

    /**
     * Установка статуса с проверкой на переход в указанный статус
     *
     * @param string $stage
     * @param string|null $substage
     *
     * @return bool
     */
    public function setStage(string $stage, ?string $substage = null): bool
    {
        $stage = $this->canGoTo($stage);

        if ($stage !== false) {
            list($stage, $substage) = $this->autoScoring($stage, $substage);
            $this->setSubStage($substage);
            $this->statement->stage_id = $stage;

            return $this->statement->save();
        }

        return false;
    }

    /**
     * Установка подстатуса
     *
     * @param $subStage
     *
     * @return bool
     */
    public function setSubStage($subStage): bool
    {
        $subStage = $this->fromNameToId($subStage);

        if ($subStage !== false) {
            if ($subStage < 100 && $subStage != 0) {
                return false;
            }
            $this->statement->substage_id = $subStage;

            return $this->statement->save();
        }

        return false;
    }

    /**
     * Установка статуса без проверки условий перехода
     *
     * @param string|int $stage
     * @param null|string|int $subStage
     *
     * @return bool
     */
    public function forceSetStage($stage, $subStage = null): bool
    {
        $stage = $this->fromNameToId($stage);
        $subStage = $this->fromNameToId($subStage);

        if ($stage !== false) {
            [$stage, $subStage] = $this->autoScoring($stage, $subStage);
            $this->statement->stage_id = $stage;

            if ($subStage !== null) {
                $this->statement->substage_id = $subStage;
            }

            return $this->statement->save();
        }

        return false;
    }

    /**
     * Проверка на необходимость провести автоскоринг.
     *
     * @param $stage
     * @param $substage
     *
     * @return array
     */
    public function autoScoring($stage, $substage): array
    {
        if ($this->statement->scoring_wait && !in_array($this->fromIdToName($stage), ['denied', 'deniedByBank', 'canceled'])) {
            $this->statement->scoring_next_stages = ['stage_id' => $stage, 'substage_id' => $substage];

            $stage = $this->fromNameToId('autoCheck');
            $substage = null;
        }

        return [$stage, $substage];
    }

    /**
     * Получение массива со всей информацией по статусам
     *
     * @param string $groupedBy
     *
     * @return array
     */
    public function getAllStages(string $groupedBy = 'id'): array
    {
        return $this->stages[$groupedBy];
    }

    /**
     * Получение текста статуса/подстатуса
     *
     * @param bool $sub показать наименование подстатуса.
     *
     * @return string
     */
    public function getStageName(bool $sub = false): string
    {
        if ($sub) {
            if ($this->getCurrentStage('is_hidden_substage')) {
                return '';
            }

            return $this->getCurrentSubStage($this->role)['text'] ?: '';
        }

        return $this->getCurrentStage($this->role)['text'] ?: '';
    }

    /**
     * Получение массива со всей информацией по текущему статусу
     *
     * @param null $field
     *
     * @return mixed
     */
    public function getCurrentStage($field = null)
    {
        if (!isset($this->getAllStages()[$this->statement->stage_id])) {
            return false;
        }

        if ($field === null) {
            return $this->getAllStages()[$this->statement->stage_id];
        } else {
            return $this->getAllStages()[$this->statement->stage_id][$field];
        }
    }

    /**
     * Получение массива со всей информацией по текущему подстатусу
     *
     * @param null $field
     *
     * @return mixed
     */
    public function getCurrentSubStage($field = null)
    {
        if (!isset($this->getAllStages()[$this->statement->substage_id])) {
            return false;
        }

        if ($field === null) {
            return $this->getAllStages()[$this->statement->substage_id];
        }

        return $this->getAllStages()[$this->statement->substage_id][$field] ?? false;
    }

    /**
     * Получение массива с набором следующих статусов и правилами перехода по ним
     *
     * @return array
     */
    public function getNextStages(): array
    {
        $nextStages = [];
        $currentStage = $this->getCurrentStage('name');
        $call = 'after' . ucfirst($currentStage);

        if (method_exists($this, $call)) {
            $nextStages = $this->$call();
        }

        $nextStages = $this->overWriteConditions($nextStages);

        return $this->filterStages($nextStages);
    }

    /**
     * Проверка на возможность перехода в указанный статус из текущего
     *
     * @param $stage
     *
     * @return bool
     */
    public function canGoTo($stage)
    {
        $stage = $this->fromNameToId($stage);

        if ($stage !== false) {
            if (in_array($stage, array_keys($this->getNextStages())) && $stage != $this->statement->stage_id) {
                return $stage;
            }
        }

        return false;
    }

    /**
     * Проверка, на чьей стороне находится заявка
     *
     * @param $side
     *
     * @return bool
     */
    public function on($side = null): bool
    {
        $side = $side ?? $this->role;
        if ($side == 'moder') $side = 'broker'; // Костыль для модератора
        if ($this->getCurrentStage('side') == $side) {
            return true;
        }

        return false;
    }

    /**
     * Проверка, находится ли текущий статус перед указанным
     *
     * @param string $stage
     * @param bool $equal
     *
     * @return bool|null
     */
    public function before(string $stage, bool $equal = false): ?bool
    {
        try {
            list($currentStagePriority, $stagePriority) = $this->getPriority($stage);
        } catch (StageException $e) {
            return null;
        }

        if ($equal) {
            return $currentStagePriority <= $stagePriority;
        } else {
            return $currentStagePriority < $stagePriority;
        }
    }

    /**
     * Проверка, находится ли текущий статус после указанным
     *
     * @param string $stage
     * @param bool $equal
     *
     * @return bool|null
     */
    public function after(string $stage, bool $equal = false): ?bool
    {
        try {
            list($currentStagePriority, $stagePriority) = $this->getPriority($stage);
        } catch (StageException $e) {
            return null;
        }

        if ($equal) {
            return $currentStagePriority >= $stagePriority;
        } else {
            return $currentStagePriority > $stagePriority;
        }
    }

    /**
     * Проверка, является ли текущий статус равным указанному
     *
     * @param $stages
     *
     * @return bool
     */
    public function is($stages): bool
    {
        if (is_array($stages)) {
            foreach ($stages as $stage) {
                if ($this->checkStage($stage)) {
                    return true;
                }
            }

            return false;
        } else {
            return $this->checkStage($stages);
        }
    }

    /**
     * Проверка, является ли текущий статус отличным от указанного
     *
     * @param $stages
     *
     * @return bool
     */
    public function isNot($stages): bool
    {
        if (is_array($stages)) {
            foreach ($stages as $stage) {
                if ($this->checkStage($stage)) {
                    return false;
                }
            }

            return true;
        } else {
            return !$this->checkStage($stages);
        }
    }

    /**
     * Метод для перезаписи статусов и их условий перехода
     *
     * @param $nextStages
     *
     * @return mixed
     */
    protected function overWriteConditions($nextStages)
    {
        return $nextStages;
    }

    /**
     * Фильтрация статусов по условиям перехода
     *
     * @param $nextStages
     *
     * @return array
     */
    protected function filterStages($nextStages)
    {
        $fullStages = [];

        foreach ($nextStages as $id => $filter) {
            if (is_array($filter) && !empty($filter)) {
                if (isset($filter['sub']) && !$this->checkStage($filter['sub'])) {
                    continue;
                }

                if (isset($filter['role']) && strpos($filter['role'], $this->role) === false) {
                    continue;
                }
            }
            $fullStages[$id] = $this->stages['id'][$id];
        }

        return $fullStages;
    }

    /**
     * Получение id статуса по его имени.
     *
     * @param string $stage
     *
     * @return bool|int
     */
    public function fromNameToId(string $stage)
    {
        if (!is_numeric($stage)) {
            if (isset($this->stages['name'][$stage])) {
                $stage = $this->stages['name'][$stage]['id'];
            } else {
                return false;
            }
        }

        return $stage;
    }

    /**
     * Получение имени статуса по его id.
     *
     * @param int $stage
     *
     * @return string
     */
    public function fromIdToName(int $stage)
    {
        if (is_numeric($stage)) {
            if (isset($this->stages['id'][$stage])) {
                $stage = $this->stages['id'][$stage]['name'];
            } else {
                return false;
            }
        }

        return $stage;
    }

    /**
     * Сравнение текущего статуса и указанного
     *
     * @param $stage
     *
     * @return bool
     */
    protected function checkStage($stage): bool
    {
        if (is_string($stage)) {
            $stage = $this->fromNameToId($stage);
        }

        if (!$stage) {
            return false;
        }

        if ($stage > 100) {
            return ($this->getCurrentSubStage('id') == $stage);
        } else {
            return ($this->getCurrentStage('id') == $stage);
        }
    }

    /**
     * Получение приоритета статуса (текущего и указанного)
     *
     * @param $stage
     *
     * @return array
     * @throws StageException
     */
    protected function getPriority($stage): array
    {
        if (is_string($stage)) {
            $stage = $this->fromNameToId($stage);
        }

        if (!$stage) {
            throw new StageException("Stage '$stage' not found for current bank");
        }

        $stagePriority = $this->stages['id'][$stage]['priority'];

        if ($stage > 100) {
            $currentStagePriority = $this->getCurrentSubStage('priority');
        } else {
            $currentStagePriority = $this->getCurrentStage('priority');
        }

        return [$currentStagePriority, $stagePriority];
    }
}
