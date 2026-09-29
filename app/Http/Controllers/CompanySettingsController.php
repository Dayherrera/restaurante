<?php
namespace App\Http\Controllers;
use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class CompanySettingsController extends Controller {
 public function edit(){Gate::authorize('users.manage');return view('settings',['company'=>CompanySetting::current()]);}
 public function update(Request $request){
  Gate::authorize('users.manage');
  $data=$request->validate(['business_name'=>'required|string|max:150','legal_name'=>'nullable|string|max:200','rfc'=>'nullable|string|max:13|regex:/^[A-Za-zÑñ&]{3,4}[0-9]{6}[A-Za-z0-9]{3}$/u','address'=>'nullable|string|max:500','phone'=>'nullable|string|max:50','email'=>'nullable|email|max:150','website'=>'nullable|url:http,https|max:200','ticket_footer'=>'nullable|string|max:500'],['rfc.regex'=>'Ingresa un RFC de 12 o 13 caracteres con formato válido.','website.url'=>'Ingresa la dirección del sitio con https:// o http://.'],['business_name'=>'nombre comercial','legal_name'=>'razón social','rfc'=>'RFC','address'=>'dirección','phone'=>'teléfono','email'=>'correo electrónico','website'=>'sitio web','ticket_footer'=>'mensaje del ticket']);
  $data['rfc']=isset($data['rfc'])?mb_strtoupper($data['rfc']):null;
  CompanySetting::current()->update($data);
  return redirect()->route('settings')->with('success','Datos de la empresa guardados. Se utilizarán en los nuevos tickets de caja y reimpresiones.');
 }
}
