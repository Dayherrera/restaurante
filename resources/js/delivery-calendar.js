import { Calendar } from '@fullcalendar/core';
import esLocale from '@fullcalendar/core/locales/es';
import dayGridPlugin from '@fullcalendar/daygrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';

window.createDeliveryCalendar = (wire, root, businessToday) => {
 const el=root.querySelector('[data-calendar]');
 if(!el || el.dataset.initialized)return;
 el.dataset.initialized='1';
 const error=root.querySelector('[data-calendar-error]');
 const loading=root.querySelector('[data-calendar-loading]');
 const dayKey=date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
 let counts={};let version=0;
 const badges=new Map();
 const updateCounts=()=>badges.forEach((button,key)=>button.textContent=`${counts[key]||0} pedidos`);
 const openDay=date=>wire.openDay(dayKey(date));
 const calendar=new Calendar(el,{
  plugins:[dayGridPlugin,listPlugin,interactionPlugin],locale:esLocale,firstDay:1,initialDate:businessToday,now:businessToday,
  initialView:window.innerWidth<700?'listMonth':'dayGridMonth',height:'auto',
  headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,dayGridWeek,listDay,listMonth'},
  buttonText:{today:'Hoy',month:'Mes',week:'Semana',day:'Día',list:'Agenda'},
  views:{dayGridWeek:{buttonText:'Semana',dayMaxEvents:false,dayMaxEventRows:false,dayHeaderFormat:{weekday:'short',day:'numeric',month:'short'}},listDay:{buttonText:'Día',displayEventEnd:false,titleFormat:{weekday:'long',day:'numeric',month:'long',year:'numeric'}},listMonth:{buttonText:'Agenda'}},
  editable:false,selectable:false,dayMaxEvents:4,eventOrder:'start,id',eventOrderStrict:true,
  eventTimeFormat:{hour:'2-digit',minute:'2-digit',hour12:false},
  events:async(info,success,failure)=>{
   const current=++version;error.hidden=true;loading.hidden=false;
   try{
    const events=await wire.events(dayKey(info.start),dayKey(info.end));
    if(current!==version){success([]);return;}
    counts={};events.forEach(e=>{if(e.extendedProps.state!=='cancelado')counts[e.extendedProps.date]=(counts[e.extendedProps.date]||0)+1;});
    success(events);updateCounts();
   }catch(e){counts={};updateCounts();error.textContent='No se pudo cargar la agenda. Pulsa Actualizar para reintentar.';error.hidden=false;success([]);}
   finally{if(current===version)loading.hidden=true;}
  },
  dayCellDidMount:info=>{
   const top=info.el.querySelector('.fc-daygrid-day-top');if(!top)return;
   const key=dayKey(info.date);const button=document.createElement('button');button.type='button';button.className='calendar-day-count';button.setAttribute('aria-label',`Ver entregas del ${key}`);button.addEventListener('click',()=>wire.openDay(key));top.after(button);badges.set(key,button);updateCounts();
  },
  dayCellWillUnmount:info=>{if(info.el.querySelector('.fc-daygrid-day-top'))badges.delete(dayKey(info.date));},
  dateClick:info=>openDay(info.date),
  moreLinkClick:info=>{openDay(info.date);return 'none';},
  eventClick:info=>{info.jsEvent.preventDefault();wire.openOrder(Number(info.event.id));},
  eventContent:info=>{
   const box=document.createElement('div');box.className='calendar-event-content';
   if(info.view.type==='dayGridWeek'){
    box.classList.add('calendar-week-row');
    const time=document.createElement('b');time.className='calendar-week-time';time.textContent=info.timeText;
    const detail=document.createElement('div');
    const name=document.createElement('strong');name.textContent=info.event.title;
    const state=document.createElement('small');state.textContent=info.event.extendedProps.label+(info.event.extendedProps.balance?' · Saldo pendiente':'')+(info.event.extendedProps.released?' · Liberado':'');
    detail.append(name,state);box.append(time,detail);return {domNodes:[box]};
   }
   const title=document.createElement('strong');title.textContent=info.view.type==='listDay'?info.event.title:`${info.timeText} ${info.event.title}`;
   const label=document.createElement('small');label.textContent=info.event.extendedProps.label+(info.event.extendedProps.balance?' · Saldo pendiente':'')+(info.event.extendedProps.released?' · Liberado':'');
   box.append(title,label);return {domNodes:[box]};
  },
 });
 calendar.render();
 const unlisten=wire.on('calendar-refresh',()=>calendar.refetchEvents());
 const cleanup=()=>{version++;calendar.destroy();unlisten?.();document.removeEventListener('livewire:navigating',cleanup);};
 document.addEventListener('livewire:navigating',cleanup,{once:true});
};
