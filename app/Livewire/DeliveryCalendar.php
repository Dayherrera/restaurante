<?php
namespace App\Livewire;
use App\Models\{Order,DeliveryDriver};
use App\Services\{TicketService,OrderService};
use Illuminate\Support\Facades\{Gate,Validator,DB};
use Livewire\Attributes\Locked;
use Livewire\Component;
class DeliveryCalendar extends Component {
 #[Locked] public ?int $orderId=null;
 #[Locked] public ?string $day=null;
 public bool $showCancelled=false;
 public string $driverId='';
 public function mount(){Gate::authorize('orders.view');}
 public static function label(Order $o):string {return $o->status==='pendiente' && !$o->production_released_at && $o->scheduled_date->toDateString()>now()->toDateString()?'Programado':(['pendiente'=>'Pendiente','en_preparacion'=>'En preparación','listo'=>'Listo','en_ruta'=>'En ruta','entregado'=>'Entregado','cancelado'=>'Cancelado'][$o->status]??$o->status);}
 public function events(string $start,string $end):array {
  Gate::authorize('orders.view');
  Validator::make(compact('start','end'),['start'=>'required|date_format:Y-m-d','end'=>'required|date_format:Y-m-d|after:start'])->validate();
  abort_if(\Carbon\Carbon::parse($start)->diffInDays(\Carbon\Carbon::parse($end))>62,422);
  return Order::whereDate('scheduled_date','>=',$start)->whereDate('scheduled_date','<',$end)->when(!$this->showCancelled,fn($q)=>$q->where('status','!=','cancelado'))->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->get()->map(fn($o)=>[
   'id'=>(string)$o->id,'title'=>$o->order_number.' · '.$o->customer_name,'start'=>$o->scheduled_date->toDateString().'T'.substr($o->scheduled_time,0,5).':00','allDay'=>false,
   'extendedProps'=>['date'=>$o->scheduled_date->toDateString(),'state'=>$o->status,'label'=>self::label($o),'balance'=>(float)$o->balance_due>0,'released'=>(bool)$o->production_released_at],
   'classNames'=>['calendar-state-'.$o->status],
  ])->all();
 }
 public function updatedShowCancelled(){$this->dispatch('calendar-refresh');}
 public function openOrder(int $id){Gate::authorize('orders.view');$o=Order::findOrFail($id);$this->orderId=$id;$this->driverId=(string)($o->delivery_driver_id??'');$this->resetValidation();}
 public function openDay(string $date){Gate::authorize('orders.view');Validator::make(['date'=>$date],['date'=>'required|date_format:Y-m-d'])->validate();$this->day=$date;$this->orderId=null;$this->resetValidation();}
 public function closePanel(){$this->orderId=null;$this->day=null;$this->resetValidation();}
 public function backToDay(){$this->orderId=null;$this->resetValidation();}
 public function refreshCalendar(){$this->dispatch('calendar-refresh');}
 public function reprint(){Gate::authorize('orders.view');Gate::authorize('orders.reprint');DB::transaction(function(){$o=Order::lockForUpdate()->findOrFail($this->orderId);app(TicketService::class)->queue($o,'reimpresion');app(OrderService::class)->audit($o,'reprint',[]);});session()->flash('calendar_notice','Reimpresión enviada. Cocina/barra solo reciben comanda si el pedido ya fue liberado.');}
 public function assignDriver(){Gate::authorize('orders.view');Gate::authorize('dispatch.manage');$this->validate(['driverId'=>'required|integer|exists:delivery_drivers,id']);DB::transaction(function(){$o=Order::lockForUpdate()->findOrFail($this->orderId);abort_if($o->delivery_type!=='domicilio'||in_array($o->status,['cancelado','entregado']),422,'Este pedido no admite asignación.');DeliveryDriver::where('is_active',true)->findOrFail($this->driverId);$o->update(['delivery_driver_id'=>$this->driverId]);app(OrderService::class)->audit($o,'driver_assigned',['delivery_driver_id'=>$this->driverId]);});session()->flash('calendar_notice','Repartidor actualizado.');}
 public function render(){Gate::authorize('orders.view');return view('livewire.delivery-calendar',[
  'selectedOrder'=>$this->orderId?Order::with('items','driver')->findOrFail($this->orderId):null,
  'dayOrders'=>$this->day?Order::whereDate('scheduled_date',$this->day)->when(!$this->showCancelled,fn($q)=>$q->where('status','!=','cancelado'))->orderBy('scheduled_time')->orderBy('id')->get():collect(),
  'drivers'=>auth()->user()->can('dispatch.manage')?DeliveryDriver::where('is_active',true)->orderBy('name')->get():collect(),
  ])->layout('components.layouts.app',['title'=>'Calendario de entregas']);}
}
