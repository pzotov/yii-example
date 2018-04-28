<?php

namespace app\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\helpers\ArrayHelper;

/**
 * Заказ от точки
 * @package app\models
 */
class Order extends ActiveRecord {
	const STATUS_ALL = -1;
	const STATUS_DRAFT = 0;
	const STATUS_DELETED = 1;
	const STATUS_HOLD = 2;
	const STATUS_PAYED = 3;
	
	protected static $statuses = [
		self::STATUS_DRAFT => 'черновик',
		self::STATUS_DELETED => 'удален',
		self::STATUS_HOLD => 'проведен',
		self::STATUS_PAYED => 'оплачен',
	];
	
	public static $filters = [
		self::STATUS_ALL => 'все',
		self::STATUS_DRAFT => 'черновики',
		self::STATUS_DELETED => 'удаленные',
		self::STATUS_HOLD => 'проведенные',
		self::STATUS_PAYED => 'завершенные',
	];
	
	const TYPE_SELLING = 0;
	const TYPE_CONSIGNMENT_1 = 1;
	const TYPE_CONSIGNMENT_2 = 2;
	
	public static $types = [
		self::TYPE_SELLING => 'простая продажа',
		self::TYPE_CONSIGNMENT_1 => 'первое пополнение консигнатора',
		self::TYPE_CONSIGNMENT_2 => 'пополнение консигнатора с продажей',
	];
	
	const PAYMENT_TYPE_BANK = 0;
	const PAYMENT_TYPE_BANK_DELAYED = 1;
	const PAYMENT_TYPE_CASH = 2;
	
	protected static $payment_types = [
		self::PAYMENT_TYPE_BANK => 'безналичный расчет',
		self::PAYMENT_TYPE_BANK_DELAYED => 'безналичный расчет с отсрочкой %d дн.',
		self::PAYMENT_TYPE_CASH => 'наличный расчет',
	];
	
	public $refill;
	
	/**
	 * @inheritdoc
	 */
	public function behaviors(){
		return [
			[
				'class' => TimestampBehavior::className(),
				'attributes' => [
					ActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
					ActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
				],
			],
		];
	}

	/**
	 * @inheritdoc
	 */
	public function rules(){
		return [
			[['comment'], 'string'],
			[['client_point_id', 'stock_id', 'type'], 'required', 'message'=>'{attribute} – обязательное поле'],
			[['client_point_id', 'stock_id', 'status', 'type', 'payment_type', 'price_type'], 'number'],
			[['refill', 'invoice_inoffice', 'invoice2_inoffice'], 'boolean']
		];
	}
	
	/**
	 * @inheritdoc
	 */
	public function attributeLabels(){
		return [
			'client_point_id' => 'Торговая точка',
			'stock_id' => 'Склад-источник',
			'status' => 'Статус',
			'type' => 'Тип заказа',
			'payment_type' => 'Способ оплаты',
			'price_type' => 'Выберите тип цены',
			'payment_date' => 'Дата, когда заказ должен быть оплачен',
			'comment' => 'Комментарий',
			'refill' => 'Пополнить точку',
			
			'date' => 'Дата',
			'client_id' => 'Клиент'
		];
	}
	
	/**
	 * Возвращает точку
	 * @return \yii\db\ActiveQuery
	 */
	public function getPoint(){
		return $this->hasOne(ClientPoint::className(), ['id' => 'client_point_id']);
	}
	
	/**
	 * Возвращает склад-источник
	 * @return \yii\db\ActiveQuery
	 */
	public function getStock(){
		return $this->hasOne(Stock::className(), ['id' => 'stock_id']);
	}
	
	/**
	 * Возвращает связанный документ-изъятие
	 * @return \yii\db\ActiveQuery
	 */
	public function getWithdrawal(){
		return $this->hasOne(Withdrawal::className(), ['id' => 'withdrawal_id']);
	}
	
	public function getStatusText(){
		return self::$statuses[$this->status];
	}
	
	public function getTypeText(){
		return self::$types[$this->type];
	}
	
	public function getPaymentTypeText(){
		return self::$payment_types[$this->payment_type];
	}
	
	public static function getPaymentTypeList(){
		return self::$payment_types;
	}
	
	/**
	 * Список товаров в заказе
	 * @return \yii\db\ActiveQuery
	 */
	public function getOrderGoods(){
		return $this->hasMany(OrderGood::className(), ['order_id' => 'id'])
			->orderBy(['id' => SORT_ASC])
			;
	}
	
	/**
	 * Список реализованных товаров в заказе
	 * @return array|ActiveRecord[]
	 */
	public function getSellingGoods(){
		switch($this->type){
			case self::TYPE_SELLING:
				return $this->orderGoods;
			case self::TYPE_CONSIGNMENT_1:
				return [];
			case self::TYPE_CONSIGNMENT_2:
				return $this->withdrawal->withdrawalGoods;
		}
	}
	
	/**
	 * Общее количество единиц товара в заказе
	 * @return int
	 */
	public function getTotalCount(){
		return (int)$this->hasMany(OrderGood::className(), ['order_id' => 'id'])
			->sum('quantity');
	}
	
	/**
	 * Суммарная стоимость товаров в заказе
	 * @return float
	 */
	public function getTotalSum(){
		return (float)$this->hasMany(OrderGood::className(), ['order_id' => 'id'])
			->sum('quantity*price');
	}
	
	/**
	 * Возвращает пользователя, создавшего заказ
	 * @return \yii\db\ActiveQuery
	 */
	public function getCreator(){
		return $this->hasOne(User::className(), ['id' => 'created_by']);
	}
	
	/**
	 * Возвращает выставленный счет
	 * @return \yii\db\ActiveQuery
	 */
	public function getBill(){
		return $this->hasOne(Bill::className(), ['order_id' => 'id'])
			->where(['!=', Bill::tableName().'.status', Bill::STATUS_CANCELED])
			;
	}
	
	/**
	 * Возвращает накладную / реализацию
	 * @return \yii\db\ActiveQuery
	 */
	public function getInvoice(){
		return $this->hasOne(Invoice::className(), ['order_id' => 'id']);
	}
	
	public function getNumber(){
		return str_pad($this->id, 6, '0', STR_PAD_LEFT);
	}
	
	public function getDate(){
		return date('d.m.Y', $this->created_at);
	}
	
}
