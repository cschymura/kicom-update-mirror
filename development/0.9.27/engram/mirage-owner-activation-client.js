/* Mirage DEV-64: separate original-KiCom signed activation of read-only MCP.
 * No passkey material, bearer or private memory persists in the browser.
 */
(() => {
 "use strict";
 const root=document.getElementById("mirage-activation");
 if(!root)return;
 const accept=document.getElementById("mirage-activation-accept");
 const button=document.getElementById("mirage-activation-button");
 const status=document.getElementById("mirage-activation-status");
 if(!(accept instanceof HTMLInputElement)
    ||!(button instanceof HTMLButtonElement)||!status)return;
 const csrf=root.dataset.csrf||"";
 const url="admin.php?engram_activate=1";
 const fromBase64=(value)=>{
   if(typeof value!=="string"||!/^[A-Za-z0-9_-]+$/.test(value))throw Error("INVALID_ENCODING");
   const data=atob(value.replace(/-/g,"+").replace(/_/g,"/")+"=".repeat((4-value.length%4)%4));
   return Uint8Array.from(data,c=>c.charCodeAt(0));
 };
 const toBase64=(buffer)=>{
   const bytes=new Uint8Array(buffer);let data="";
   for(let i=0;i<bytes.length;i+=8192)data+=String.fromCharCode(...bytes.subarray(i,i+8192));
   return btoa(data).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"");
 };
 const send=async(payload)=>{
   const response=await fetch(url,{
     method:"POST",credentials:"same-origin",cache:"no-store",redirect:"error",
     headers:{"Content-Type":"application/json","Accept":"application/json"},
     body:JSON.stringify({csrf,...payload})
   });
   if(response.status!==200)throw Error("REQUEST_DENIED");
   const result=await response.json();
   if(result.ok!==true)throw Error("REQUEST_DENIED");
   return result;
 };
 accept.addEventListener("change",()=>{button.disabled=!accept.checked;});
 button.addEventListener("click",async()=>{
   if(button.disabled||!accept.checked)return;
   button.disabled=true;
   status.textContent="KiCom-Passkey wird zur gesonderten Aktivierung geprüft …";
   try{
     if(!window.PublicKeyCredential||!navigator.credentials)throw Error("PASSKEY_UNAVAILABLE");
     const initiated=await send({step:"begin"});
     const options=initiated.publicKey;
     options.challenge=fromBase64(options.challenge);
     options.allowCredentials=options.allowCredentials.map(item=>({...item,id:fromBase64(item.id)}));
     const credential=await navigator.credentials.get({publicKey:options});
     if(!credential||!credential.response)throw Error("PASSKEY_DENIED");
     const assertion={
       id:credential.id,rawId:toBase64(credential.rawId),
       response:{
         clientDataJSON:toBase64(credential.response.clientDataJSON),
         authenticatorData:toBase64(credential.response.authenticatorData),
         signature:toBase64(credential.response.signature)
       }
     };
     const done=await send({
       step:"confirm",challenge_id:initiated.challenge_id,
       confirmation:initiated.confirmation,assertion
     });
     if(done.code!=="PRIVATE_ENGRAM_OAUTH_MCP_READ_ACTIVATED"
        ||done.oauth_enabled!==true ||done.mcp_enabled!==true
        ||done.memory_write_enabled!==false)throw Error("UNEXPECTED_STATE");
     status.textContent="Engram-Lesezugriff und OAuth aktiviert. Als Nächstes kann ChatGPT verbunden werden.";
   }catch(_error){
     status.textContent="Aktivierung nicht bestätigt. Bitte den aktuellen Status in der KiCom-Administration prüfen, bevor du die Seite neu lädst.";
   }
 });
})();
