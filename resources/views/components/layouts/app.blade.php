@props(['title' => 'Operación'])
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title }} · Los Magueyes</title>@vite(['resources/css/app.css','resources/js/app.js'])@livewireStyles</head>
<body><div class="app-shell"><aside class="sidebar"><a href="{{ url('/') }}" class="brand"><span class="brand-icon">M</span><span>LOS MAGUEYES<small>CHAROLAS & BOTANAS</small></span></a><div class="nav-label">OPERACIÓN</div><nav>
@foreach([['pos','pos.sell','Punto de venta','＋'],['orders','orders.view','Pedidos','▤'],['calendar','orders.view','Calendario','▦'],['dispatch','dispatch.manage','Cocina y despacho','◫'],['cash','cash.manage','Mi caja','▣'],['reports','reports.view','Reportes','↗']] as [$route,$permission,$label,$icon])
@can($permission)<a class="nav-item {{ request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route) }}"><span>{{ $icon }}</span>{{ $label }}</a>@endcan
@endforeach
</nav><div class="nav-label">ADMINISTRACIÓN</div><nav>
@foreach([['catalog','catalog.manage','Catálogo','◇'],['categories','catalog.manage','Categorías','▦'],['customers','pos.sell','Clientes','◉'],['drivers','dispatch.manage','Repartidores','→'],['printing','printing.manage','Impresión','▥'],['users','users.manage','Usuarios y roles','◎'],['settings','users.manage','Configuración general','⚙']] as [$route,$permission,$label,$icon])
@can($permission)<a class="nav-item {{ request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route) }}"><span>{{ $icon }}</span>{{ $label }}</a>@endcan
@endforeach
</nav><div class="sidebar-foot"><span class="online-dot"></span> Sucursal principal<small>Sistema de operación · MXN</small></div></aside><div class="workspace"><header class="topbar"><div class="breadcrumb">Sucursal principal <span>/</span> <b>{{ $title }}</b></div><div class="user-menu"><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><span>{{ auth()->user()->name }}<small>{{ auth()->user()->roles->pluck('name')->join(', ') }}</small></span><form method="POST" action="{{ route('logout') }}">@csrf<button class="link-button" type="submit">Salir</button></form></div></header><main>
@if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
{{ $slot }}
</main></div></div>@livewireScripts</body></html>

