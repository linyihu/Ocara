<?php
/*************************************************************************************************
 * -----------------------------------------------------------------------------------------------
 * Ocara开源框架    日期处理插件Date
 * Copyright (c) http://www.ocara.cn All rights reserved.
 * -----------------------------------------------------------------------------------------------
 * @author Lin YiHu <linyhtianwa@163.com>
 ************************************************************************************************/
namespace Ocara\Service;

use Ocara\Core\ServiceBase;

class Date extends ServiceBase
{
	protected static $_map = array(
		'year' 	 => 'year', 
		'month'  => 'mon', 
		'day' 	 => 'mday', 
		'hour' 	 => 'hours', 
		'minute' => 'minutes', 
		'second' => 'seconds', 
	);

	/**
	 * 获取日期信息
	 * @param string|numric $time
	 */
	public static function getDateInfo($time)
	{
		$dateInfo = array();

		if (is_string($time)) {
			$dateInfo = self::_getDateInfo($time);
		} elseif (is_numeric($time)) {
			$data = getdate($time);
			foreach (self::$_map as $key => $value) {
				$dateInfo[$key] = $data[$value];
			}
		} 
		
		return $dateInfo;
	}

	/**
	 * 设置时间参数
	 * @param string|numric $time
	 * @param integer $number
	 * @param string $type
	 */
	public static function set($time, $number, $type)
	{
		$dateInfo = self::getDateInfo($time);
		
		if ($dateInfo) {
			if (array_key_exists($type, self::$_map)) {
				$dateInfo[$type] = abs($number);
				self::checkDate($dateInfo);
			}
			return self::getDate($dateInfo);
		}
		
		return false;
	}

	/**
	 * 获取时间参数
	 * @param string|numric|array $time
	 * @param string $type
	 */
	public static function get($time, $type)
	{
		$dateInfo = self::getDateInfo($time);
		
		if (array_key_exists($type, self::$_map)) {
			return ocGet($type, $dateInfo, 0);
		}
		
		return 0;
	}

	/**
	 * 增加时间
	 * @param string|numric|array $time
	 * @param integer $number
	 * @param string $type
	 * @param string $format
	 */
	public static function add($time, $number, $type, $format = null)
	{
		$time = self::getDate($time, $format);
		
		if ($time) {
			if (array_key_exists($type, self::$_map)) {
				$sign = $number < 0 ? '-' : '+';
				$number = abs($number);
				return self::getDate(strtotime("{$time} {$sign} {$number} {$type}"), $format);
			}
		}
		
		return false;
	}

	/**
	 * 获取时间字符串
	 * @param string|numric|array $time
	 * @param string $format
	 */
	public static function getDate($time, $format = null)
	{
		$timestamp = self::getTimestamp($time);
		$format = $format == 'mdy' ? 'm-d-Y' : 'Y-m-d';

		return date($format . ' H:i:s', $timestamp);
	}

	/**
	 * 获取时间间隔
	 * @param string|numric $startTime
	 * @param string|numric $endTime
	 * @param string $type
	 */
	public static function getInterval($startTime, $endTime, $type = null)
	{
		$start = self::getTimestamp($startTime);
		$end   = self::getTimestamp($endTime);
		
		if ($start && $end) {
			$diff = $end - $start;
			$days = floor($diff / (3600 * 24));
			
			$diff  = $diff % (3600 * 24);
			$hours = floor($diff / 3600);
			
			$diff    = $diff % 3600;
			$minutes = floor($diff / 60);
			$seconds = $diff % 60;
		} else 
			list($days, $hours, $minutes, $seconds) = array_fill(0, 4, 0);
		
		if (array_key_exists(rtrim($type, 's'), self::$_map)) {
			return $$type;
		} else {
			return compact('days', 'hours', 'minutes', 'seconds');
		}
	}

	/**
	 * 生成时间戳
	 * @param string|numric|array $time
	 */
	public static function getTimestamp($time)
	{
		if (is_numeric($time)) {
			return $time;
		} elseif (is_string($time)) {
			return strtotime($time);
		} elseif (is_array($time)) {
			return mktime(
				$time['hour'],  $time['minute'], $time['second'],
				$time['month'], $time['day'], 	 $time['year']
			);
		}
		
		return 0;
	}

	/**
	 * 是否是闰年
	 * @param integer $year
	 */
	public static function isYun($year)
	{
		return $year % 4 == 0 && $year % 100 != 0 || $year % 400 == 0;
	}

	/**
	 * 内部函数-根据时间字符串获取时间参数
	 * @param string $string
	 * @param string $format
	 */
	protected static function _getDateInfo($string, $format = null)
	{
		if (!is_string($string)) return $string;
		
		if ($format == 'mdy') {
			$regStr = '/^(\d{1,2})-(\d{1,2})-(\d{4})\s(\d{1,2}):(\d{1,2}):(\d{1,2})$/';
		} else {
			$regStr = '/^(\d{4})-(\d{1,2})-(\d{1,2})\s(\d{1,2}):(\d{1,2}):(\d{1,2})$/';
		}
		
		if (is_string($string) && preg_match($regStr, $string, $mt)) 
		{
			array_shift($mt);
			if ($format == 'mdy') {
				list($month, $mday, $year, $hour, $minute, $second) = $mt;
			} else {
				list($year, $month, $day, $hour, $minute, $second) = $mt;
			}
			$dateInfo = compact('hour', 'minute', 'second', 'month', 'day', 'year');
			return self::checkDate($dateInfo);
		}
		
		return array();
	}
	
	/**
	 * 检测日期
	 * @param array $dateInfo
	 */
	public static function checkDate($dateInfo)
	{
		extract($dateInfo);
		
		$msg = 'fault_time_number';
		
		if ($month > 12 || $day > 31 || $hour > 24 || $minute > 60 || $second > 60) {
			self::showError($msg);
		}
		
		if (self::isYun($year)) {
			if ($month == 2 && $day > 29) {
				self::showError($msg);
			}
		} else {
			if ($month == 2 && $day > 28) {
				self::showError($msg);
			}
		}
		
		return $dateInfo;
	}
}