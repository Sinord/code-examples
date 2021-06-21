<?php

namespace App\Libraries\Accounting;

use App\Banks\Base\BaseAccountingCheck;
use App\Events\Document\AccountingSaveEvent;
use App\Interfaces\ChildStmtInterface;
use App\Models\Bank;
use App\Models\BuhValue;
use App\Models\Client;
use App\Models\StatementBank;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Accounting
{
    const FIRST_DAY_TYPE = 'first';
    const LAST_DAY_TYPE = 'last';
    const DEFAULT_DATE_FORMAT = 'd_m_Y';

    /** @var array|int[] */
    private array $periodMonth = [1, 4, 7, 10];
    /** @var array */
    protected array $data = [];
    /** @var Client */
    protected Client $client;
    /** @var Bank|null */
    protected ?Bank $bank = null;

    /** @var array */
    protected array $groups = [];
    /** @var array */
    protected array $cellsByGroup = [];
    /** @var array */
    protected array $cellsByCode = [];
    /** @var array */
    protected array $periods = [];
    /** @var string */
    protected string $lastPeriod;
    /** @var int */
    protected int $lastPeriodNumber = 1;
    /** @var array */
    protected array $yearPeriods = [];
    /** @var array */
    protected array $excelRows = [];

    /** @var string */
    protected string $dateType = self::FIRST_DAY_TYPE;
    /** @var Carbon */
    protected Carbon $activeDate;

    /**
     * Accounting constructor.
     *
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Генерация стандартного пакета БО
     *
     * @return array
     */
    public function generate(): array
    {
        return $this->getData();
    }

    /**
     * Генерация пакета БО для конкретной заявки
     *
     * @param ChildStmtInterface $stmt
     *
     * @return array
     */
    public function generateForStmt(ChildStmtInterface $stmt): array
    {
        if ($activeDate = $stmt->getFields()->accounting_date) {
            $this->activeDate = Carbon::createFromFormat('d.m.Y', $activeDate);
        }

        return $this->generateForBank($stmt->bank);
    }

    /**
     * Генерация пакета БО для конкретного банка
     *
     * @param Bank $bank
     *
     * @return array
     */
    public function generateForBank(Bank $bank): array
    {
        $this->bank = $bank;

        return $this->generate();
    }

    /**
     * Генерация пакета БО для конкретного банка
     *
     * @param Bank $bank
     *
     * @return array
     */
    public function generateForDownload(Bank $bank): array
    {
        $this->bank = $bank;

        return $this->generate();
    }

    /**
     * Формирование данных
     *
     * @return array
     */
    public function getData(): array
    {
        if (empty($this->data)) {
            $this->getDataFullPack();
        }

        return [
            'lastPeriod'       => $this->lastPeriod,
            'lastPeriodNumber' => $this->lastPeriodNumber,
            'yearPeriods'      => $this->yearPeriods,
            'periods'          => $this->periods,
            'groups'           => $this->groups,
            'cellsByGroup'     => $this->cellsByGroup,
            'cellsByCode'      => $this->cellsByCode,
            'values'           => $this->data,
        ];
    }

    /**
     * Получение соответствий строк БО и Excel-файла для выгрузки данных
     *
     * @return array
     */
    public function getExcelRows(): array
    {
        return $this->excelRows;
    }

    /**
     * Сохранение данных БО с последующим запуском формирования финансовых показателей
     *
     * @param array $data
     * @param StatementBank|null $childStmt
     */
    public function save(array $data, StatementBank $childStmt = null)
    {
        $this->periods = $this->getPeriods();
        $this->data = $data;

        if ($childStmt) {
            $this->childStmt = $childStmt;
            $this->startCheck();
        }

        $this->saveToDB();
    }

    /**
     * @param $type
     */
    public function setPeriodType($type)
    {
        $this->dateType = $type;
    }

    /**
     * @param $format
     *
     * @return array
     */
    public function customPeriodFormat($format): array
    {
        $customFormat = [];
        foreach ($this->periods as $period) {
            $periodCarbon = Carbon::createFromFormat('d_m_Y', $period);
            $customFormat[$period] = $this->makeDateFormat($periodCarbon, $format, true);
        }

        return $customFormat;
    }

    /**
     * Формирование пакета данных (периоды, значения и тд)
     */
    private function getDataFullPack()
    {
        $this->groups = [];
        $this->cellsByGroup = [];
        $this->cellsByCode = [];
        $showSmallTitle = [];

        if ($this->bank) {
            $cells = $this->getTableStructureForBank();
        } else {
            $cells = $this->getTableStructureUnique();
        }

        foreach ($cells as $c) {
            $bigId = $c['big_group_id'];

            if (!isset($this->cellsByGroup[$bigId])) {
                $this->cellsByGroup[$bigId] = [];
                $this->groups[$bigId] = $c['name'];
            }

            if ($c['params']) {
                $c['params'] = json_decode($c['params'], 1);
            }

            if (!in_array($c['group_id'], $showSmallTitle) && $c['is_show_name']) {
                $c['showSmallTitle'] = true;
                $showSmallTitle[] = $c['group_id'];
            } else {
                $c['showSmallTitle'] = false;
            }

            $this->cellsByGroup[$bigId][] = $c;
            $this->cellsByCode[$c['code']] = $c;
            $this->excelRows[$c['code']] = $c['excel_row'] ?? null;
        }

        $this->periods = $this->getPeriods();
        $this->data = $this->getValues();
    }

    /**
     * Получение требуемых групп и показателей для банка
     *
     * @return array
     */
    private function getTableStructureForBank(): array
    {
        $result = collect(DB::table('buh_bank_req as br')
                            ->select('*', 'g.name as small_name', 'bg.name as big_name')
                            ->leftJoin('buh_fields as f', 'f.id', '=', 'br.field_id')
                            ->leftJoin('buh_groups as g', 'g.id', '=', 'f.group_id')
                            ->leftJoin('buh_big_groups as bg', 'bg.id', '=', 'g.big_group_id')
                            ->where('br.bank_id', $this->bank->id)
                            ->orderByRaw('big_group_id, group_id, formula, code')
                            ->get());

        return $result->sortBy(function ($row, $key) {
            $code = (int)$row['code'];

            if ($code < 2000) {
                return $key;
            }

            return ($code % 100 == 0) ? $code + 100 : $code;
        })->all();
    }

    /**
     * Получение требуемых групп и показателей по умолчанию
     *
     * @return array
     */
    private function getTableStructureUnique(): array
    {
        return DB::table('buh_fields as f')
                 ->select('*', 'g.name as small_name', 'bg.name as big_name')
                 ->leftJoin('buh_groups as g', 'g.id', '=', 'f.group_id')
                 ->leftJoin('buh_big_groups as bg', 'bg.id', '=', 'g.big_group_id')
                 ->orderByRaw('big_group_id, group_id, formula, code')
                 ->get();
    }

    /**
     * Формирование динамических периодов
     *
     * @return array
     */
    private function getPeriods(): array
    {
        $numPeriods = $this->bank ? (int)$this->bank->buh_periods_num : 5;
        $numYears = $this->bank ? (int)$this->bank->buh_years_num : 1;

        $periods = [];

        $activeDate = $this->activeDate ?? Carbon::now();
        $i = $z = 0;
        $y = $activeDate->year;
        $last = false;

        while ($i < $numPeriods) {
            $month = $this->periodMonth[3 - $z];
            if ((($month + 1) <= $activeDate->format('n') && $y == $activeDate->year) || $y < $activeDate->year) {
                if (!$last) {
                    $this->lastPeriod = $this->makePeriodDate($month, $y);
                    $this->lastPeriodNumber = $z + 1;
                    $last = true;
                }
                $periods[] = $this->makePeriodDate($month, $y);
                $i++;

                if ($month === 1) {
                    $numYears--;
                    $this->yearPeriods[] = $this->makePeriodDate($month, $y);
                }
            }

            $z++;

            if ($z > 3) {
                $y--;
                $z = 0;
            }
        }

        if ($this->bank) {
            for ($z = 0; $z < $numYears; $z++) {
                $this->yearPeriods[] = $this->makePeriodDate(1, $y);
                $periods[] = $this->makePeriodDate(1, $y);
                $y--;
            }
        }

        return array_unique($periods);
    }

    /**
     * @param        $month
     * @param        $year
     *
     * @return string
     */
    private function makePeriodDate($month, $year): string
    {
        $period = Carbon::create($year, $month, 1, 0, 0, 0);

        return $this->makeDateFormat($period, self::DEFAULT_DATE_FORMAT);
    }

    /**
     * @param Carbon $period
     * @param        $format
     * @param bool $checkType
     *
     * @return string
     */
    private function makeDateFormat(Carbon $period, $format, bool $checkType = false): string
    {
        if ($this->dateType == self::LAST_DAY_TYPE && $checkType) {
            $period->subDay();
        }

        return $period->format($format);
    }

    /**
     * Получние данных БО клиента
     *
     * @return array
     */
    private function getValues(): array
    {
        $values = [];
        $value = $this->client
                      ->buhValues()
                      ->formatPeriod()
                      ->whereIn('period', $this->customPeriodFormat('Y-m-d'))
                      ->orderBy('period', 'desc')
                      ->get();

        foreach ($value->all() as $valueGroup) {
            $values[$valueGroup->format_period] = $valueGroup->values;
        }

        return $values;
    }
}