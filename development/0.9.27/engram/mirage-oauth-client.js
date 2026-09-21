/* Mirage Engram DEV-55: original first-party KiCom OAuth WebAuthn UI.
 * No secrets, private memories or authorization state are persisted locally.
 * Human must check explicit read consent before requesting passkey.
 */
(() => {
  "use strict";
  const root=document.getElementById("mirage-oauth-consent");
  if (!root) return;
  const consent=document.getElementById("mirage-oauth-consent-check");
  const button=document.getElementById("mirage-oauth-consent-button");
  const status=document.getElementById("mirage-oauth-consent-status");
  const cancel=document.getElementById("mirage-oauth-cancel-button");
  if (!(consent instanceof HTMLInputElement) ||
      !(button instanceof HTMLButtonElement) || !status) return;
  const csrf=root.dataset.csrf || "";
  const requestId=root.dataset.requestId || "";
  const url="admin.php?engram_oauth=1";
  const fromB64=(value) => {
    if(typeof value!=="string" || !/^[A-Za-z0-9_-]+$/.test(value)) throw Error("encoding");
    const normalized=value.replace(/-/g,"+").replace(/_/g,"/");
    const binary=atob(normalized+"=".repeat((4-normalized.length%4)%4));
    return Uint8Array.from(binary,c=>c.charCodeAt(0));
  };
  const toB64=(buffer) => {
    const bytes=new Uint8Array(buffer);
    let binary="";
    for(let i=0;i<bytes.length;i+=8192)
      binary+=String.fromCharCode(...bytes.subarray(i,i+8192));
    return btoa(binary).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"");
  };
  const send=async(payload)=>{
    const response=await fetch(url,{
      method:"POST",
      credentials:"same-origin",
      redirect:"error",
      headers:{"Content-Type":"application/json","Accept":"application/json"},
      cache:"no-store",
      body:JSON.stringify(Object.assign({csrf,request_id:requestId},payload)),
    });
    if(response.status!==200) throw Error("request denied");
    const result=await response.json();
    if(result.ok!==true) throw Error("request denied");
    return result;
  };
  consent.addEventListener("change",()=>{
    button.disabled=!consent.checked;
  });
  if (cancel instanceof HTMLButtonElement) cancel.addEventListener("click",async()=>{
    if(cancel.disabled)return;
    cancel.disabled=true;
    button.disabled=true;
    status.textContent="Verbindung wird abgelehnt …";
    try {
      const result=await send({step:"cancel"});
      if(typeof result.redirect_to!=="string")throw Error("redirect missing");
      const url=new URL(result.redirect_to);
      if(url.protocol!=="https:" || url.hostname!=="chatgpt.com"
         || url.searchParams.get("error")!=="access_denied")throw Error("redirect denied");
      window.location.assign(url.href);
    }catch(_error){
      status.textContent="Ablehnung nicht übermittelt. Bitte diese Seite schließen.";
    }
  });
  button.addEventListener("click",async()=>{
    if(!consent.checked || button.disabled) return;
    button.disabled=true;
    status.textContent="KiCom-Passkey wird geprüft …";
    try {
      if(!window.PublicKeyCredential || !navigator.credentials) throw Error("passkey unsupported");
      const started=await send({step:"challenge"});
      const options=started.publicKey;
      options.challenge=fromB64(options.challenge);
      options.allowCredentials=options.allowCredentials.map(item=>Object.assign({},item,{
        id:fromB64(item.id)
      }));
      const credential=await navigator.credentials.get({publicKey:options});
      if(!credential || !credential.response) throw Error("passkey not selected");
      const assertion={
        id:credential.id,
        rawId:toB64(credential.rawId),
        response:{
          clientDataJSON:toB64(credential.response.clientDataJSON),
          authenticatorData:toB64(credential.response.authenticatorData),
          signature:toB64(credential.response.signature)
        }
      };
      const confirmed=await send({
        step:"confirm",challenge_id:started.challenge_id,
        assertion,consent:true
      });
      if(typeof confirmed.redirect_to!=="string") throw Error("redirect missing");
      const target=new URL(confirmed.redirect_to);
      if(target.protocol!=="https:" || target.hostname!=="chatgpt.com")
        throw Error("redirect not pinned");
      status.textContent="Verbindung freigegeben. Weiterleitung zu ChatGPT …";
      window.location.assign(target.href);
    }catch(_error){
      status.textContent="Freigabe nicht abgeschlossen. Bitte Seite neu laden und erneut bestätigen.";
    }
  });
})();
