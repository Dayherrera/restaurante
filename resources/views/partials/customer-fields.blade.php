<div class="field-grid"><label>Nombre del cliente *<input wire:model="customerForm.name" required maxlength="120" autocomplete="name"></label><label>Teléfono *<input wire:model="customerForm.phone" required maxlength="25" inputmode="tel" autocomplete="tel"></label></div>
<div class="field-grid"><label>Calle<input wire:model="customerForm.street" maxlength="150"></label><label>Número<input wire:model="customerForm.number" maxlength="30" placeholder="Ej. 144 o S/N"></label></div>
<label>Colonia<input wire:model="customerForm.neighborhood" maxlength="120"></label>
<div class="field-grid"><label>Ciudad<input wire:model="customerForm.city" required maxlength="120"></label><label>Estado<input wire:model="customerForm.state" required maxlength="100"></label></div>
<label>Referencias<textarea wire:model="customerForm.references" rows="2" maxlength="500" placeholder="Entre calles, color de fachada, indicaciones..."></textarea></label>
<p class="muted tiny">La dirección completa se requiere para entrega a domicilio.</p>
