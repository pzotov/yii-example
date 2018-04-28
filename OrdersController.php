<?php

namespace app\controllers;

use app\models\About;
use app\models\Bill;
use app\models\Client;
use app\models\ClientPoint;
use app\models\DocumentTemplate;
use app\models\Good;
use app\models\PointGood;
use app\models\Order;
use app\models\OrderGood;
use app\models\User;
use app\models\Stock;
use app\models\Withdrawal;
use app\models\WithdrawalGood;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use yii\data\Pagination;
use yii\helpers\Html;

/**
 * Заказы от точек
 * @package app\controllers
 */
class OrdersController extends Controller {
	/**
	 * @inheritdoc
	 */
	public function behaviors(){
		return [
			'access' => [
				'class' => AccessControl::className(),
				'rules' => [
					[
						'allow' => true,
						'roles' => ['@'],
					],
				],
			],
		];
	}
	
	/**
	 * Список заказов
	 *
	 * @return string
	 */
	public function actionIndex(){
		$session = Yii::$app->session;
		$request = Yii::$app->request;
		
		$filter = [
			'status' => Order::STATUS_DRAFT,
			'search' => '',
			'date' => '',
			'client_id' => '',
			'stock_id' => ''
		];
		if(!$request->get('reset_filter')){
			if($session->has($this->id.'_filter')) $filter = array_merge($filter, $session[$this->id.'_filter']);
			if($filter1 = $request->get("filter")) $filter = array_merge($filter, $filter1);
		}
		
		$query = Order::find()
//			->where(['deleted' => 0])
		;
		if(User::isAgent()){
			$clients_ids = Client::find()
				->select('id')
				->where([
					'deleted' => 0,
					'responsible_id' => Yii::$app->user->identity->id
				])
				->column()
			;
			$query->joinWith(['point'])
				->andWhere(['in', ClientPoint::tableName().'.client_id', $clients_ids]);
		}
		
		if($filter['status']!=Order::STATUS_ALL){
			$query->andWhere(['status' => $filter['status']]);
		}
		if($filter['search']){
			$query
				->joinWith(['point', 'stock'])
				->andWhere(['like', Order::tableName().'.id', $filter['search']])
				->orWhere(['like', Order::tableName().'.comment', $filter['search']])
				->orWhere(['like', ClientPoint::tableName().'.name', $filter['search']])
				->orWhere(['like', Stock::tableName().'.name', $filter['search']])
			;
		}
		if($filter['date']){
			$query
				->andWhere(['>=', 'created_at', strtotime($filter['date'].' 00:00:00')])
				->andWhere(['<=', 'created_at', strtotime($filter['date'].' 23:59:59')])
			;
		}
		if($filter['stock_id']){
			$query
				->andWhere(['stock_id' => $filter['stock_id']])
			;
		}
		if($filter['client_id']){
			$query
				->joinWith(['point'])
				->andWhere([ClientPoint::tableName().'.client_id' => $filter['client_id']])
			;
		}
		if(User::isAgent()){
			$query->andWhere(['created_by' => Yii::$app->user->identity->id]);
		}
		
		$countQuery = clone $query;
		$pages = new Pagination([
			'totalCount' => $countQuery->count(),
			'pageSize' => 100,
			'page' => $request->get('page', 1)-1
		]);
		$pages->params['filter'] = $filter;
		$session[$this->id.'_filter'] = $filter;
//		$pages->route = [$this->id.'/'.$this->action->id];
		
		$rows = $query
			->offset($pages->offset)
			->limit($pages->limit)
			->orderBy([
				'created_at' => SORT_DESC
			])
			->all();
		
		return $this->render('index', [
			'rows' => $rows,
			'filter' => $filter,
			'pages' => $pages
		]);
	}
	
	/**
	 * Добавление/редактирование информации о заказе
	 *
	 * @return string
	 */
	public function actionEdit($id = 0){
		$request = Yii::$app->request;
		$session = Yii::$app->session;
		
		if($id){
			//Пока что редактирование запретить
			if(!($order = Order::findOne($id))){
				$session->addFlash("error", "Неверный ID заказа");
				return $this->redirect(['orders/index'], 302);
			}
			if($order->status==Order::STATUS_HOLD) {
				$session->addFlash("error", "Заказ #{$order->id} уже проведен");
				return $this->redirect(['orders/view', 'id' => $order->id], 302);
			}
			if(count($order->orderGoods)) $order->refill = true;
		} else {
			$order = new Order();
			$order->created_by = Yii::$app->user->identity->id;
			$order->type = $request->get('type');
			$order->price_type = Good::PRICE_DEFAULT;
			if(User::isAgent()){
				$order->stock_id = Yii::$app->user->identity->stock->id;
			} else {
				$order->stock_id = 0;
			}
			if(($client_id = $request->get("client_id")) &&
				($client = Client::findOne($client_id))
//				&& $client->private
			){
				$order->client_point_id = $client->points[0]->id;
				$order->payment_type = Order::PAYMENT_TYPE_CASH;
			}
//			switch($order->type){
//				case Order::TYPE_CONSIGNMENT_1:
//				case Order::TYPE_CONSIGNMENT_2:
//					$order->price_type = Good::PRICE_SELLING;
//					break;
//				default:
//					$order->price_type = Good::PRICE_DEFAULT;
//					break;
//			}
		}
		
		if($request->isPost){
			if($order->load($request->post()) && $order->save()){
				$goods_quantity = $request->post('goods_quantity');
				$goods_price = $request->post('goods_price');
				OrderGood::deleteAll(['order_id' => $order->id]);
				if($order->type!=Order::TYPE_CONSIGNMENT_2 || $order->refill){
					foreach ($goods_quantity as $good_id => $quantity){
						$order_good = new OrderGood();
						$order_good->good_id = $good_id;
						$order_good->order_id = $order->id;
						$order_good->quantity = $quantity;
						$order_good->price = $goods_price[$good_id];
						$order_good->save();
					}
				}
				//Записываем расчетную итоговую сумму в поле total_sum для большей скорости дальнейших расчетов
				$order->total_sum = $order->totalSum;
				//Для заказов типа консигнация + продажа нужно создать изъятие вникуда (продали), если не создано
				if($order->type==Order::TYPE_CONSIGNMENT_2){
					if(!$order->withdrawal_id){
						$withdrawal = new Withdrawal();
						$withdrawal->stock_id = 0;
						$withdrawal->client_point_id = $order->client_point_id;
						$withdrawal->status = Withdrawal::STATUS_DRAFT;
						$withdrawal->created_by = Yii::$app->user->identity->id;
						$withdrawal->save();
						$order->withdrawal_id = $withdrawal->id;
					}
					WithdrawalGood::deleteAll(['withdrawal_id' => $order->withdrawal_id]);
					$stocks_quantity = $request->post('stocks_quantity');
					foreach($stocks_quantity as $good_id => $quantity){
//						if(!$quantity) continue;
						$withdrawal_good = new WithdrawalGood();
						$withdrawal_good->good_id = $good_id;
						$withdrawal_good->price = $order->point->getPrice($good_id) ?: $withdrawal_good->good->price1;
						$withdrawal_good->quantity = $order->point->getQuantity($good_id) - $quantity;
						if(!$withdrawal_good->quantity) continue;
						$withdrawal_good->withdrawal_id = $order->withdrawal_id;
						$withdrawal_good->save();
					}
				}
				//Для консигнаторов нужно записать планы
				if($order->type==Order::TYPE_CONSIGNMENT_1 || $order->type==Order::TYPE_CONSIGNMENT_2){
					$goods_plan = $request->post('goods_plan');
					if(is_array($goods_plan)){
						foreach($goods_plan as $good_id => $quantity){
							if(!($pointGood = PointGood::find()
								->where([
									'client_point_id' => $order->client_point_id,
									'good_id' => $good_id
								])
								->one())){
								$pointGood = new PointGood();
								$pointGood->client_point_id = $order->client_point_id;
								$pointGood->good_id = $good_id;
								$pointGood->quantity = 0;
							}
							$pointGood->plan = $quantity;
							$pointGood->save();
						}
					}
				}
				$order->save();
				
				$session->addFlash("success", "Сохранено");
				if($request->post('hold')) return $this->redirect(['orders/hold', 'id' => $order->id], 302);
				else return $this->redirect(['orders/index'], 302);
			}
			$session->addFlash("error", "Ошибка сохранения");
		}
		return $this->render('edit_'.$order->type, [
			'model' => $order
		]);
	}
	
	/**
	 * Помечает перемещение удаленным
	 *
	 * @param $id
	 * @return Response
	 */
	public function actionDelete($id){
		$session = Yii::$app->session;

		if(!$id || !($order = Order::findOne($id)) || $order->status==Order::STATUS_HOLD){
			$session->addFlash("error", "Неверный ID заказа");
		} else {
			$order->status = Order::STATUS_DELETED;
			$order->save();
			$session->addFlash("success", "Заказ помечен удаленным");
		}
		return $this->redirect(['orders/index'], 302);
	}
	
	/**
	 * Восстанавливает перемещение из удаленных
	 *
	 * @param $id
	 * @return Response
	 */
	public function actionUndelete($id){
		$session = Yii::$app->session;

		if(!$id || !($order = Order::findOne($id)) || $order->status!=Order::STATUS_DELETED){
			$session->addFlash("error", "Неверный ID заказа");
		} else {
			$order->status = Order::STATUS_DRAFT;
			$order->save();
			$session->addFlash("success", "Заказ восстановлен");
		}
		return $this->redirect(['orders/index'], 302);
	}
	
	/**
	 * Поиск товаров по наименованию или артикулу, а также по выбранному поставщику
	 * Исключает товары, которые уже введены в этом перемещении
	 * @return string
	 */
	public function actionSuggestGoods(){
		$request = Yii::$app->request;
		$session = Yii::$app->session;
		
		$stock_id = $request->get("stock_id");
		$point_id = $request->get("point_id");
		$exclude = $request->get("exclude", []);
		$term = $request->get("term", '');
		$price_type = $request->get("price_type", 0);
		$result = [];
		
		if(!$point_id || !($point = ClientPoint::findOne($point_id))){
			$point = null;
		}
		
		if($term && ($goods = Good::find()
				->where(['like', 'name', $term])
				->orWhere(['like', 'sku', $term])
				->andWhere(['not in', 'id', $exclude])
				->andWhere(['deleted' => 0])
				->orderBy(['name' => SORT_ASC])
				->all())){
			foreach($goods as $good){
				//Если товара нет в наличии на выбранном складе
				$max_quantity = $good->stockQuantity($stock_id);
//				if(!($max_quantity = $good->stockQuantity($stock_id)) ||
					// или товара на складе меньше, чем минимальная партия для отгрузки,
					// то пропускаем этот товар мимо
//					$max_quantity < $good->min_quantity_out) continue;
				$item = [
					'id' => $good->id,
					'sku' => $good->sku,
					'name' => $good->name,
					'price' => @$good->{"price".$price_type} ?: $good->price1,
					'all_prices' => $good->allPricesJson,
					'unit' => $good->unit->name,
					'quantity' => min($good->min_quantity_out, $max_quantity),
//					'exist' => $point->getQuantity($good->id),
//					'plan' => $point->getPlan($good->id),
					'exist' => 0,
					'plan' => 0,
					'min_quantity' => min($good->min_quantity_out, $max_quantity),
					'max_quantity' => $max_quantity,
				];
				if($point){
					$item['exist'] = $point->getQuantity($good->id);
					$item['plan'] = $point->getPlan($good->id);
					$item['quantity'] = $point->getPlan($good->id) - $point->getQuantity($good->id);
					if($item['quantity']<0) $item['quantity'] = 0;
				}
				$result[] = $item;
			}
		}
		
		return json_encode($result);
	}
	
	/**
	 * Поиск точки по адресу, названию или клиенту
	 * @return string
	 */
	public function actionSuggestPoints(){
		$request = Yii::$app->request;
		$session = Yii::$app->session;
		
		$term = $request->get("term", '');
		$order_type = $request->get("order_type", '');
		$result = [];
		
		if($term){
			$points = ClientPoint::find()
				->joinWith('client')
				->where(['like', ClientPoint::tableName().'.name', $term])
				->orWhere(['like', 'index1', $term])
				->orWhere(['like', 'region1', $term])
				->orWhere(['like', 'city1', $term])
				->orWhere(['like', 'street1', $term])
				->orWhere(['like', 'building1', $term])
				->orWhere(['like', 'office1', $term])
				->orWhere(['like', Client::tableName().'.name', $term])
				;
			if(User::isAgent()){
				$clients_ids = Client::find()
					->select('id')
					->where([
						'deleted' => 0,
						'responsible_id' => Yii::$app->user->identity->id
					])
					->column();
				$points->andWhere(['in', 'client_id', $clients_ids]);
			}
			$points = $points
				->orderBy([
					Client::tableName().'.name' => SORT_ASC,
					ClientPoint::tableName().'.name' => SORT_ASC
				])
				->all();
			if($points){
				foreach ($points as $point){
					//Проверяем, есть ли у точки подписанный договор, чтобы отправлять на консигнацию
					if(($order_type == Order::TYPE_CONSIGNMENT_1 || $order_type == Order::TYPE_CONSIGNMENT_2) && !$point->consignmentAllowed) continue;
					$item = [
						'id' => $point->id,
						'name' => $point->name,
						'client_name' => $point->client->name,
						'address' => $point->fullAddress,
						'installment_days' => $point->installmentDays,
						'payment_types' => $point->paymentTypes,
						'stocks' => [],
						'refill_goods' => []
					];
					foreach ($point->pointGoods as $point_good){
						if($point_good->quantity){
							$item['stocks'][] = [
								'id' => $point_good->good_id,
								'sku' => $point_good->good->sku,
								'name' => $point_good->good->name,
								'unit' => $point_good->good->unit->name,
								'price' => $point_good->good->price1,
								'all_prices' => $point_good->good->allPricesJson,
								'quantity' => $point_good->quantity,
								'new_quantity' => $point_good->quantity,
							];
						}
						if($point_good->plan){
							$item['refill_goods'][] = [
								'id' => $point_good->good_id,
								'sku' => $point_good->good->sku,
								'name' => $point_good->good->name,
								'unit' => $point_good->good->unit->name,
								'price' => $point_good->good->price1,
								'all_prices' => $point_good->good->allPricesJson,
								'min_quantity' => '',
								'max_quantity' => '',
								'quantity' => $point_good->plan - $point_good->quantity,
								'exist' => $point_good->quantity,
								'plan' => $point_good->plan,
							];
						}
					}
					$result[] = $item;
				}
			}
		}
		
		return json_encode($result);
	}
	
	/**
	 * Попытка провести заказ
	 * @param $id
	 * @return string
	 */
	public function actionHold($id){
		$session = Yii::$app->session;
		
		if(!$id || !($order = Order::findOne($id)) || $order->status==Order::STATUS_HOLD || $order->status==Order::STATUS_DELETED){
			$session->addFlash("error", "Неверный ID заказа");
		} else {
			//проверяем достаточное наличие выбранных товаров на выбранном складе
			$order_goods = $order->orderGoods;
			foreach($order_goods as $order_good){
				if($order_good->quantity > $order_good->good->stockQuantity($order->stock_id)){
					$session->addFlash("error",
						"Недостаточное количество товара \"".
						$order_good->good->name.
						"\" на складе \"".
						$order->stock->name."\""
					);
					return $this->redirect(['orders/view', 'id' => $order->id], 302);
				}
			}
			reset($order_goods);
			foreach($order_goods as $order_good){
				$order->stock->removeGood($order_good->good_id, $order_good->quantity);
				$order->point->addGood($order_good->good_id, $order_good->quantity, $order_good->price);
			}
			$order->status = Order::STATUS_HOLD;
			$order->save();
			
			//Если с заказом связано изъятие, то его тоже проводим
			if($order->type==Order::TYPE_CONSIGNMENT_2 && $order->withdrawal_id){
				//проверяем достаточное наличие выбранных товаров на выбранном складе
				foreach($order->withdrawal->withdrawalGoods as $withdrawal_good){
					$order->point->removeGood($withdrawal_good->good_id, $withdrawal_good->quantity);
				}
				$order->withdrawal->status = Withdrawal::STATUS_HOLD;
				$order->withdrawal->save();
			}
			
			if($order->type==Order::TYPE_SELLING || $order->type==Order::TYPE_CONSIGNMENT_2){
				//Создаем счет на оплату
				$bill = new Bill();
				$bill->order_id = $order->id;
				$bill->status = Bill::STATUS_WAITING;
				$bill->save();
			}
			
			$session->addFlash("success", "Заказ успешно проведен");
		}
		return $this->redirect(['orders/view', 'id' => $order->id], 302);
	}
	
	/**
	 * Попытка отменить проведение заказ
	 * @param $id
	 * @return string
	 */
	public function actionUnhold($id){
		$session = Yii::$app->session;
		
		if(!$id || !($order = Order::findOne($id)) || $order->status!=Order::STATUS_HOLD){
			$session->addFlash("error", "Неверный ID заказа");
		} else {
			//проверяем достаточное наличие выбранных товаров на точке, куда отправили товары
			$order_goods = $order->orderGoods;
			foreach($order_goods as $order_good){
				if($order_good->quantity > $order->point->getQuantity($order_good->good_id)){
					$session->addFlash("error",
						"Недостаточное количество товара \"".
						$order_good->good->name.
						"\" на точке \"".
						$order->point->name."\""
					);
					return $this->redirect(['orders/view', 'id' => $order->id], 302);
				}
			}
			reset($order_goods);
			//возвращаем на склад товар, забираем его с точки
			foreach($order_goods as $order_good){
				$order->stock->addGood($order_good->good_id, $order_good->quantity);
				$order->point->removeGood($order_good->good_id, $order_good->quantity);
			}
			$order->status = Order::STATUS_DRAFT;
			$order->save();
			
			//Если с заказом связано изъятие, то его тоже отменяем
			if($order->type==Order::TYPE_CONSIGNMENT_2 && $order->withdrawal_id){
				//проверяем достаточное наличие выбранных товаров на выбранном складе
				foreach($order->withdrawal->withdrawalGoods as $withdrawal_good){
					$order->point->addGood($withdrawal_good->good_id, $withdrawal_good->quantity);
				}
				$order->withdrawal->status = Withdrawal::STATUS_DRAFT;
				$order->withdrawal->save();
			}
			if($order->bill){
				$order->bill->status = Bill::STATUS_CANCELED;
				$order->bill->save();
			}
			
			$session->addFlash("success", "Проведение заказа успешно отменено");
		}
		return $this->redirect(['orders/view', 'id' => $order->id], 302);
	}
	
	/**
	 * Накладная на продажу
	 * @param int $id
	 * @return bool|null|string
	 */
	public function actionInvoice($id = 0){
		$session = Yii::$app->session;
		
		if(!$id || !($order = Order::findOne($id))){
			$session->addFlash("error", "Неверный ID заказа");
			return $this->redirect(['orders/index'], 302);
		}
		if($order->type==Order::TYPE_CONSIGNMENT_1){
			$session->addFlash("error", "Неверный тип заказа");
			return $this->redirect(['orders/view', 'id' => $id], 302);
		}

		$extension = 'xls';
		
		$point = $order->point;
		
		$items = [];
		if($order->type==Order::TYPE_SELLING){
			$order_goods = $order->orderGoods;
			$totalSum = $order->totalSum;
			$totalCount = $order->totalCount;
		} else {
			$order_goods = $order->withdrawal->withdrawalGoods;
			$totalSum = $order->withdrawal->totalSum;
			$totalCount = $order->withdrawal->totalCount;
		}
		foreach($order_goods as $index => $order_good){
			$good = $order_good->good;
			$items[] = [
				'index' => $index+1,
				'sku' => $good->sku,
				'name' => $good->name,
				'price' => $order_good->price,
				'quantity' => $order_good->quantity,
				'sum' => $order_good->quantity * $order_good->price,
				'unit' => $good->unit->name,
			];
		}
		
		$about = About::getObject();
		
		$invoice = DocumentTemplate::createInvoice([
			'number' => $order->number,
			'date' => $order->date,
			
			'from' => $about->formal_name.', '.$about->formal_address,
			'name' => $point->name,
			'postal_address' => $point->fullAddress,
			
			'items' => $items,
			
			'total.count' => $totalCount,
			'total.sum' => $totalSum,
			'total.count_script' => DocumentTemplate::sumInWords($totalCount, false, false),
			'total.sum_script' => DocumentTemplate::sumInWords($totalSum)
		], $extension);
		
		ob_clean();
		header('Content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="'.Html::encode('Реализация '.$order->number).'.'.$extension.'"');
		return $invoice;
	}
	
	/**
	 * Накладная на пополнение точки
	 * @param int $id
	 * @return bool|null|string
	 */
	public function actionInvoice2($id = 0){
		$session = Yii::$app->session;
		
		if(!$id || !($order = Order::findOne($id))){
			$session->addFlash("error", "Неверный ID заказа");
			return $this->redirect(['orders/index'], 302);
		}
		if($order->type==Order::TYPE_SELLING){
			$session->addFlash("error", "Неверный тип заказа");
			return $this->redirect(['orders/view', 'id' => $id], 302);
		}
		
		$extension = 'xls';
		
		$point = $order->point;
		
		$items = [];
		$order_goods = $order->orderGoods;
		$totalSum = $order->totalSum;
		$totalCount = $order->totalCount;

		foreach($order_goods as $index => $order_good){
			$good = $order_good->good;
			$items[] = [
				'index' => $index+1,
				'sku' => $good->sku,
				'name' => $good->name,
				'price' => $order_good->price,
				'quantity' => $order_good->quantity,
				'sum' => $order_good->quantity * $order_good->price,
				'unit' => $good->unit->name,
			];
		}
		
		$about = About::getObject();
		
		$invoice = DocumentTemplate::createInvoice([
			'number' => $order->number,
			'date' => $order->date,
			
			'from' => $about->formal_name.', '.$about->formal_address,
			'name' => $point->name,
			'postal_address' => $point->fullAddress,
			
			'items' => $items,
			
			'total.count' => $totalCount,
			'total.sum' => $totalSum,
			'total.count_script' => DocumentTemplate::sumInWords($totalCount, false, false),
			'total.sum_script' => DocumentTemplate::sumInWords($totalSum)
		], $extension);
		
		ob_clean();
		header('Content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="'.Html::encode('Накладная К-'.$order->number).'.'.$extension.'"');
		return $invoice;
	}
	
	/**
	 * Страница заказа
	 * @param $id
	 * @return string|Response
	 */
	public function actionView($id){
		$session = Yii::$app->session;
		
		if(!$id || !($order = Order::findOne($id))){
			$session->addFlash("error", "Неверный ID заказа");
			return $this->redirect(['orders/index'], 302);
		}
		
		return $this->render('view', [
			'model' => $order
		]);
	}
}
