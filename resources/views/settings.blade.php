<x-layouts.app title="Configuración general">
<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1>Configuración general</h1><p class="muted">Datos de la empresa para el ticket de caja del cliente.</p></div></div>
<form method="POST" action="{{ route('settings.update') }}" class="card form-stack" style="max-width:800px">
@csrf
<h2>Datos de la empresa</h2>
<label>Nombre comercial *<input name="business_name" value="{{ old('business_name',$company->business_name) }}" maxlength="150" required></label>
<div class="field-grid"><label>Razón social<input name="legal_name" value="{{ old('legal_name',$company->legal_name) }}" maxlength="200"></label><label>RFC<input name="rfc" value="{{ old('rfc',$company->rfc) }}" maxlength="13" placeholder="Opcional"></label></div>
<label>Dirección del establecimiento<textarea name="address" maxlength="500" rows="3" placeholder="Calle, número, colonia, ciudad, estado y código postal">{{ old('address',$company->address) }}</textarea></label>
<div class="field-grid"><label>Teléfono / WhatsApp<input name="phone" value="{{ old('phone',$company->phone) }}" maxlength="50" type="tel"></label><label>Correo electrónico<input name="email" value="{{ old('email',$company->email) }}" maxlength="150" type="email"></label></div>
<label>Sitio web<input name="website" value="{{ old('website',$company->website) }}" maxlength="200" type="url" placeholder="https://..."></label>
<h2>Mensaje al cliente</h2>
<label>Texto al pie del ticket<textarea name="ticket_footer" maxlength="500" rows="3" placeholder="Gracias por su preferencia">{{ old('ticket_footer',$company->ticket_footer) }}</textarea></label>
<p class="muted">Solo el nombre comercial es obligatorio. Los campos vacíos no se imprimen.</p>
<button class="primary" type="submit">Guardar configuración</button>
</form></x-layouts.app>
