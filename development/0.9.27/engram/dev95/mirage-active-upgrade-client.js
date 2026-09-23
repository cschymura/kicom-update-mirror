/* DEV-95: one simple action, original KiCom WebAuthn and CSRF.
 * This page never receives tokens, memory data or private filesystem paths.
 */
(() => {
 "use strict";
 const root=document.getElementById("mirage-active-upgrade");
 if(!root)return;
 const button=document.getElementById("mirage-active-upgrade-button");
 const status=document.getElementById("mirage-active-upgrade-status");
 if(!(button instanceof HTMLButtonElement)||!status)return;
 const csrf=root.dataset.csrf||"";
 const endpoint="admin.php?engram_active_upgrade=1";
 const decode=value=>{
   if(typeof value!=="string"||!/^[A-Za-z0-9_-]+$/.test(value))throw Error("BAD_ENCODING");
   const binary=atob(value.replace(/-/g,"+").replace(/_/g,"/")
     +"=".repeat((4-value.length%4)%4));
   return Uint8Array.from(binary,c=>c.charCodeAt(0));
 };
 const encode=buffer=>{
   const bytes=new Uint8Array(buffer);let binary="";
   for(let i=0;i<bytes.length;i+=8192)
     binary+=String.fromCharCode(...bytes.subarray(i,i+8192));
   return btoa(binary).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"");
 };
 const send=async payload=>{
   const response=await fetch(endpoint,{
     method:"POST",credentials:"same-origin",cache:"no-store",redirect:"error",
     headers:{"Content-Type":"application/json","Accept":"application/json"},
     body:JSON.stringify({csrf,...payload})
   });
   if(response.status!==200)throw Error("FREIGABE_NICHT_ERFOLGT");
   const data=await response.json();
   if(data.ok!==true)throw Error("FREIGABE_NICHT_ERFOLGT");
   return data;
 };
 button.addEventListener("click",async()=>{
   if(button.disabled)return;
   button.disabled=true;
   status.textContent="KiCom-Passkey bestätigen …";
   try {
     if(!window.PublicKeyCredential||!navigator.credentials)throw Error("PASSKEY_UNAVAILABLE");
     const begin=await send({step:"begin"});
     const options=begin.publicKey;
     options.challenge=decode(options.challenge);
     options.allowCredentials=options.allowCredentials.map(x=>({...x,id:decode(x.id)}));
     const credential=await navigator.credentials.get({publicKey:options});
     if(!credential||!credential.response)throw Error("PASSKEY_DENIED");
     const assertion={
       id:credential.id,rawId:encode(credential.rawId),
       response:{
         clientDataJSON:encode(credential.response.clientDataJSON),
         authenticatorData:encode(credential.response.authenticatorData),
         signature:encode(credential.response.signature)
       }
     };
     const done=await send({step:"confirm",challenge_id:begin.challenge_id,
       confirmation:"FREIGABE",assertion});
     if(done.code!=="PRIVATE_SCHEMAS_PREPARED"
         ||done.oauth_backup_created!==true
         ||done.engram_backup_created!==true
         ||done.write_scope_activated!==false)throw Error("INCOMPLETE_MIGRATION");
     status.textContent="Datenbanken vorbereitet und privat gesichert. Schreibzugriff bleibt deaktiviert.";
   }catch(_error){
     status.textContent="Vorbereitung nicht bestätigt. Bitte den aktuellen Zustand in der Administration prüfen.";
   }
 });
})();
