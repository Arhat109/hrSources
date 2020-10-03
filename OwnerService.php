<?php

namespace Firm\Maintenance;

use Crm\Model\Maintenance\MaintenanceManager;
use Leads\Utils;
use PDO;
use RO\Db;
use RO\Vault;
use TContactManager;
use TFirm;
use TManager;
use TOtdel;

/**
 * Class OwnerService -- класс новых правил поддержки закреплений/откреплений менеджеров от карточек фирм
 *
 * ТПН20200610: Добавить блок 2см св-зеленым, если это "клиент":
 * ТПН20200610: дополнить правила по "И": "если сумма оплат за 18мес > 10тыс" (#1) и "если сумма оплат за 9мес > 10тыс" (№2)
 *
 * 20200610 Правило1: Фирма является клиентом, если присутствуют оплаченные счета за последний год от "сейчас"
 * 20200612 Доп.Правило1: И если сумма проплат за 18мес > 10тыс, И если сумма оплат за 9мес>10тыс.
 * 20200615 Доп.Правило1: ИЛИ не платил за последние полгода..
 *
 * Правило2. ТПН20200629: отобразить дату слета как: 45дней + max(01.07.2020,датаПривязкиМенеджера) если он есть(был)
 *   иначе написать: "Свободна для работы" (зел. фон).
 *   Возможно продление по +60 дней.
 *
 * @author fvn20200630 .. вынесено в сервис из вида, т.к. "разрастается" до самостоятельной единицы..
 * .. дополнительно: разгрузка глобального вертолета "/object/.." перенос в модуль /firm/..
 *
 * @package Firm\Maintenance Новое управление закреплениями фирм за менеджерами и правила слета фирм
 */
class OwnerService
{

    public const RULE1_INTERVAL1 = 60*60*24*365.25;  // year from now
    public const RULE1_INTERVAL2 = 60*60*24*30.5*18; // 18 monthes from now
    public const RULE1_INTERVAL3 = 60*60*24*30.5*9;  // 9 month from now
    public const RULE1_PAY = 10000.0;                // border for payed summ
    public const RULE1_INTERVAL4 = 60*60*24*182.625; // 6 monthes from now

//    public const RULE2_STARTDATE = 1593561600;       // === strtotime('2020-07-01 00:00:00');
    public const RULE2_STARTDATE = 1597449600;       // === strtotime('2020-08-15 00:00:00');
    public const RULE2_END_PERIOD = 60*60*24*45;     // period the lost firm from start date or manager owners that is more

    public const RULE2_ADD_PERIOD = 60*60*24*60;     // adding to date lost for continuing manager owners
    public const RULE2_ADD_DAYS   = 60;

    /**
     * @return false|string -- текстовка до какой даты это КЛИЕНТ или false
     * @param TFirm $firm
     */
    public static function getLastClientDate(TFirm $firm)
    {
        $lastClientDate = false;
        $lastDatetime = 0;

        // 1. последний оплаченный счет раньше чем 1 год назад от "сегодня".
        $lastSchet = $firm->getLastPayedSchet();
        if( isset($lastSchet) && (time() - $lastSchet->tsschet) <= (int)self::RULE1_INTERVAL1 ){
            $pays = $firm->getLastPays(time() - self::RULE1_INTERVAL2);
//die(var_dump('schet18m', self::RULE1_INTERVAL2, time()-self::RULE1_INTERVAL2, date('d.m.Y H:i:s', time()-self::RULE1_INTERVAL2), $pays));
            if( $pays > self::RULE1_PAY ){
                $lastDatetime = $lastSchet->tsschet + self::RULE1_INTERVAL1;
            }
        }

        // 2. ИЛИ платил не позднее 6 месяцев от "сегодня".
        $lastPay = $firm->getLastPayment();
        if( isset($lastPay) && time() - $lastPay->tscreate <= self::RULE1_INTERVAL4 ){
            $pays = $firm->getLastPays(time() - self::RULE1_INTERVAL3);
//die(var_dump('pay9m', self::RULE1_INTERVAL3, time()-self::RULE1_INTERVAL3, date('d.m.Y H:i:s', time()-self::RULE1_INTERVAL3),$pays));
            if( $pays > self::RULE1_PAY ){
                $payDate = $lastPay->tscreate + self::RULE1_INTERVAL4;
                $lastDatetime = ($lastDatetime < $payDate)? $payDate : $lastDatetime;
            }
        }
        if( $lastDatetime > 0 ){
            $lastClientDate = 'до: ' . date('d.m.Y', $lastDatetime);
        }
        return $lastClientDate;
    }

    /**
     * @return int - дата слета менеджера с фирмы timestamp
     * RULE2 calculating by tslink (created)
     * @param TContactManager $link
     */
    public static function calcLostDate(TContactManager $link)
    {
        $tslink = strtotime($link->tslink);
        return max(self::RULE2_STARTDATE, $tslink) + self::RULE2_END_PERIOD;
    }

    /**
     * @return string -- SQL запрос поиска фирм - "НЕ КЛИЕНТ" согласно правилам.. без LIMIT
     *
     * @param string $where -- подготовленная строка условий, если надо
     */
    public static function sqlFirmLost(string $where = '', $calcRows='') : string
    {
        $rule1time1 = (int)self::RULE1_INTERVAL1;
        $rule1time2 = (int)self::RULE1_INTERVAL2;
        $rule1time3 = (int)self::RULE1_INTERVAL3;
        $rule1sum = self::RULE1_PAY;

        $rule2time  = (int)self::RULE2_STARTDATE;
        $rule2period = (int)self::RULE2_END_PERIOD;

        if( !empty($where) ){ $where = 'WHERE '.$where; }

        $sql = <<<SQL
SELECT {$calcRows} f.idfirm, fkm.iidmanager AS idmanager, f.strbrendname, m.strmanager
  , IFNULL(fkm.lostDate, GREATEST({$rule2time}, UNIX_TIMESTAMP(fkm.tslink)) + {$rule2period}) AS lostDate
  , MAX(s.idschet) AS lastSchet -- , SUM(s.fitogo) AS summSchets, COUNT(s.idschet) cntSchets
--  , SUM(p.fsumma) pays18
  , SUM(IF(p.tscreate >= UNIX_TIMESTAMP() - {$rule1time3}, p.fsumma, 0.0)) AS pays09
--  , MAX(p.tscreate) lastPay
FROM tblfirm f
JOIN tblfirmkontaktmanager fkm ON f.idfirm = fkm.iidfirm
JOIN tblmanager m ON m.idmanager = fkm.iidmanager
LEFT JOIN tschet s ON s.blive=1 AND s.iidcustomerfirm = f.idfirm AND s.iidmanager = fkm.iidmanager
                      AND s.fitogo_oplata > 0.01 AND UNIX_TIMESTAMP() <= s.tsschet + {$rule1time1}
LEFT JOIN tplatez p ON s.idschet IS NOT NULL AND p.blive=1 AND s.idschet = p.iidschet AND p.tscreate >= UNIX_TIMESTAMP() - {$rule1time2}
{$where}
GROUP BY f.idfirm, fkm.iidmanager
HAVING lastSchet IS NULL OR pays09 <= {$rule1sum}
-- HAVING lastSchet IS NULL OR (pays18 <= {$rule1sum} AND pays09 <= {$rule1sum} AND lastPay >= UNIX_TIMESTAMP() - {$rule1time2})
ORDER BY lostDate, f.idfirm
SQL;
        /*
        SELECT SQL_CALC_FOUND_ROWS f.idfirm, IFNULL(fkm.lostDate, GREATEST({$rule2time}, UNIX_TIMESTAMP(fkm.tslink)) + {$rule2period}) AS lostDate
          , 0.0 AS pays18, 0.0 AS pays09, NULL AS lastPay
        FROM tblfirm f
        JOIN tblfirmkontaktmanager fkm ON f.idfirm = fkm.iidfirm
        LEFT JOIN tschet s ON f.idfirm = s.iidcustomerfirm AND s.fitogo_oplata > 0.01 AND UNIX_TIMESTAMP() <= s.tsschet + {$rule1time1}
        WHERE {$where} AND s.idschet IS NULL

        UNION DISTINCT
        SELECT * FROM (
            SELECT f.idfirm, IFNULL(fkm.lostDate, GREATEST({$rule2time}, UNIX_TIMESTAMP(fkm.tslink)) + {$rule2period}) AS lostDate
                 , SUM(p.fsumma) pays18
                 , SUM(IF(p.tscreate >= UNIX_TIMESTAMP() - {$rule1time3}, p.fsumma, 0.0)) AS pays09
                 , MAX(p.tscreate) lastPay
            FROM tblfirm f
                JOIN tblfirmkontaktmanager fkm ON f.idfirm = fkm.iidfirm
                JOIN tschet s
                ON f.idfirm = s.iidcustomerfirm AND s.fitogo_oplata > 0.01 AND UNIX_TIMESTAMP() <= s.tsschet + {$rule1time1}
                JOIN tplatez p ON f.idfirm = p.iidcustomerfirm AND p.tscreate >= UNIX_TIMESTAMP() - {$rule1time2}
            WHERE {$where}
            GROUP BY f.idfirm
            HAVING pays18 < {$rule1sum} AND pays09 < {$rule1sum}
        ) tmp
        ORDER BY lostDate
        {$limit}
        ;
        */
//die(var_dump($sql));
        return $sql;
    }

    /** @return array -- список идентов фирм, со статусом НЕ КЛИЕНТ (Правило2)
     * @param int|array $iidmanager -- для кого
     * @param array     $pages      -- INOUT данные пагинатора
     */
    public static function findFirmLost($iidmanager, &$pages)
    {
        $where = '';
            if( is_array($iidmanager) ){ $where = 'fkm.iidmanager IN('.implode(',',$iidmanager).')'; }
        elseif( is_int($iidmanager)   ){ $where = 'fkm.iidmanager ='.$iidmanager;                          }

        $limit = ' LIMIT '.$pages['offset'].','.$pages['onpage'];

        $sql = self::sqlFirmLost($where, 'SQL_CALC_FOUND_ROWS') . $limit;
//die(var_dump($sql));

        $rows = [];
        $stmt = Db::query($sql);
        if( $stmt ){
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = Db::query('SELECT found_rows();');
            $pages['maxrows'] = $stmt->fetchColumn();
        }
//die(var_dump($rows));
        return empty($rows)? $rows : Utils::index($rows, ['idfirm','idmanager']);
    }

    /**
     * @return array -- возвращает список ид фирм "не клиентов" с количеством звонков подходящих под условия
     *
     * @param array $filterCall -- фильтр по датам звонков и их длительности [fromDate,toDate,duration[from,up],having[cond, count]]
     * .. гдеесли задан having: cond{'>'|'<'|'='} заданному кол-ву
     * @param array $pages      -- страницы пагинатора
     *
     * !!! iidvidconnect = 1 -- это телефоны!
     * !!! iidobject = 5     -- контактер - фирма
     * @see ats/library/FvnPhoneService.php (недописано!)
     */
    public static function findFirmLostByCalls(array $filter, &$pages)
    {
//die(var_dump($filter));
        $sqlLost = self::sqlFirmLost();

        $wheress = [];
        if( !empty($filter['dateFrom']) ){ $wheres[] = 'ls.calldate >= "'.$filter['dateFrom'].' 00:00:00"'; }
        if( !empty($filter['dateTo'])   ){ $wheres[] = 'ls.calldate <= "'.$filter['dateTo'].' 23:59:59"'; }
        if( !empty($filter['secFrom'])  ){ $wheres[] = 'ls.duration >= '.(int)$filter['secFrom']; }
        if( !empty($filter['secTo'])    ){ $wheres[] = 'ls.duration <= '.(int)$filter['secTo'];   }

        $having = '';
        if( !empty($filter['havingCount']) ){
            $cond = LostCallsFilter::$conds[$filter['havingCond']];
            $having = "HAVING count {$cond} {$filter['havingCount']}";
        }

        $where = empty($wheres)? '' : ' AND ('.implode(') AND (', $wheres).')';

        // UNION because different index for stat.log_asterisk and need OR condition!
        $sql = <<<SQL
SELECT SQL_CALC_FOUND_ROWS tmp3.idfirm, tmp3.strbrendname, COUNT(tmp3.duration) AS count
  , GROUP_CONCAT(
      CONCAT_WS('|', tmp3.calldate, tmp3.src_e164, tmp3.dst_e164, tmp3.duration, tmp3.recordingfile)
      SEPARATOR '##'
  ) AS callData
FROM (
  SELECT tmp.idfirm, tmp.strbrendname, ls.duration, ls.calldate, ls.src_e164, ls.dst_e164, ls.recordingfile FROM ({$sqlLost}) tmp
  JOIN lnk_object_vidconnect lo ON lo.iidvidconnect=1 AND lo.iidobject=5 AND lo.blive2=1 AND lo.iidlnkobject = tmp.idfirm
  JOIN tbltelefon tf ON tf.idtelefon = lo.iidlnkvidconnect AND tf.blive=1
  JOIN stat.log_asterisk ls ON (ls.dst_e164 = tf.e164){$where}

  UNION DISTINCT

  SELECT tmp.idfirm, tmp.strbrendname, ls.duration, ls.calldate, ls.src_e164, ls.dst_e164, ls.recordingfile FROM ({$sqlLost}) tmp
  JOIN lnk_object_vidconnect lo ON lo.iidvidconnect=1 AND lo.iidobject=5 AND lo.blive2=1 AND lo.iidlnkobject = tmp.idfirm
  JOIN tbltelefon tf ON tf.idtelefon = lo.iidlnkvidconnect AND tf.blive=1
  JOIN stat.log_asterisk ls ON (ls.src_e164 = tf.e164){$where}
) tmp3
GROUP BY tmp3.idfirm
{$having}
LIMIT {$pages['offset']},{$pages['onpage']}
;
SQL;
// \RO\Debug::var_dump($sql); // die('sql');

        $rows = [];
        $stmt = Db::query($sql);
        if( $stmt ){
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = Db::query('SELECT found_rows();');
            $pages['maxrows'] = $stmt->fetchColumn();
        }
        return $rows;
    }

    /**
     * @return array -- [out,losts|false] таблица вывода для managers.phtml И список закреплений фирмы по владельцам (м.б.>1) или false
     *
     * @param TFirm $firm
     * @throws \RO\Db\DbException
     * @TODO Разделить getListMaintenance() ! Возвращает эклектику .. думать, там большой объем вычислений.. поправить возврат тут
     */
    public static function getLosts(TFirm $firm)
    {
        $isDateLost = false;

        /**
         * @var MaintenanceManager $maintenanceManager
         * @var array      $out  -- текстовки вида, из "общего метода" тут не нужны..
         * @var TManager[] $mans -- менеджеры - владельцы карточки фирмы
         * @var TOtdel[]   $deps -- отделы - владельцы (тут не нужны тоже)
         */
        $maintenanceManager = Vault::get(MaintenanceManager::class);
        [$out, $deps, $mans] = $firm->getListMaintenance();

        // Если есть менеджер(ы) то ищем дату(ы):
        if( !empty($mans) ){
            // Права! Если можно - показ всех, иначе только своих:
            $user = Vault::getUser();

            $isDateLost = true;
            $losts = ['mans'=>[],'html'=>[]];
            foreach( $mans as $man ){
                // пропуск всего, что показывать тут нельзя:
                if( $maintenanceManager->getAccessLevel($firm, $man) == TFirm::ACC_NONE ){
                    continue;
                }

                // ищем привязку фирмы к менеджеру и смотрим дату
                /** @var TContactManager[] $links */
                $links = TContactManager::Mapper()->findAll(['iidmanager' => $man->idmanager, 'iidfirm' => $firm->id]);
                if( empty($links) || count($links) > 1 ){
                    throw new \Exception(
                        "
ERROR! Менеджер {$man->strmanager}({$man->idmanager}) связан с фирмой {$firm->strbrendname} ({$firm->id}) больше одного раза .. косяк в БД!
"
                    );
                }
                $link = $links[0];
                $dLost = date('d.m.Y'
                    , $link->lostDate ?? self::calcLostDate($link) // if not present - calculating
                );

                // сразу то что надо отрисовать в виде:
//debug:        $dlink = date('d.m.Y H:i:s', $tslink);
                $losts['html'][] = "\n<br/>{$man->strmanager}, дата слета: {$dLost}"; // ({$dlink})";
                $losts['mans'][] = $man;
            }
        }
        return [$out, ($isDateLost? $losts : false)];
    }

    /** @return array -- [iidfirm=>[iidmanager=>[sumSchets, summPays]]] суммы счетов и оплат по фирме(ам) и менеджеру(ам)
     *
     * @param int|array $iidfirm
     * @param int|array $iidmanager
     */
    public static function getTotalSumm($iidfirm, $iidmanager)
    {
        $firms = $managers = '';
        if( !empty($iidfirm) ){
            if( is_array($iidfirm) ){ $firms = 's.iidcustomerfirm IN('.implode(',',$iidfirm).')'; }
            else                    { $firms = 's.iidcustomerfirm ='.(int)$iidfirm;                     }
        }
        if( !empty($iidmanager) ){
            if( is_array($iidmanager) ){ $managers = 's.iidmanager IN('.implode(',',$iidmanager).')'; }
            else                       { $managers = 's.iidmanager ='.(int)$iidmanager;                     }
        }
        $where = $firms;
        $where .= ($where==''? '' : ' AND ').$managers;

        $rows = [];
        $sql = <<<SQL
SELECT s.iidcustomerfirm AS idfirm, s.iidmanager AS idmanager
     , COUNT(s.idschet) AS countSchets, SUM(s.fitogo) AS summSchets, SUM(lp.summPays) AS summPays
FROM tschet s
LEFT JOIN (
    SELECT p.iidschet, SUM(p.fsumma) AS summPays FROM tplatez p GROUP BY p.iidschet
) lp ON s.idschet = lp.iidschet
WHERE s.blive=1 AND {$where}
GROUP BY s.iidcustomerfirm, s.iidmanager
SQL;
//die(var_dump($sql));
        $rows = [];
        $stmt = Db::query($sql);
        if( $stmt ){
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return empty($rows)? $rows : Utils::index($rows, ['idfirm', 'idmanager']);
    }

    /**
     * Поиск закрепления фирмы за менеджером И установка даты слета.
     * Если задана новая дата, то её. Иначе(!) изменение "на период" (+/-)
     *
     * @param int        $iidfirm
     * @param int        $iidmanager
     * @param int|string $newDate -- if string 'Y-m-d H:i:s' format!
     * @param int        $period
     */
    public static function setLostDate(int $iidfirm, int $iidmanager, $newDate, $period=0)
    {
//die(var_dump($newDate, $period));
        $links = TContactManager::Mapper()->findAll(['iidfirm'=>$iidfirm, 'iidmanager'=>$iidmanager]);
        if( empty($links) ){
            $user = Vault::getUser();
            $link = new TContactManager();
            $link->iidfirm = $iidfirm;
            $link->iidmanager = $iidmanager;
            $link->iidoperator = $user->id;
        }else{
            $link = $links[0];
        }

        // absent lostDate - calculate it!
        if( empty($link->lostDate) ){ $link->lostDate = self::calcLostDate($link); }

        if( empty($newDate) ){
            // new lost date is absent -- see period +/- in days
            if( !empty($period) ){
                $link->lostDate += $period * 60*60*24;
            }
        }else{
            // set newDate as lost
            if( is_string($newDate) ){ $newDate = strtotime($newDate); }

            $link->lostDate = $newDate;
        }
        $link->save();
    }
}