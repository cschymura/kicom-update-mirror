/* Mirage DEV-63: original KiCom admin-only, explicit signed policy acceptance.
 * No credential data, access token or private memory stored in the browser.
 */
(() => {
  "use strict";
  const root=document.getElementById("mirage-host-policy");
  if(!root)return;
  const accept=document.getElementById("mirage-host-policy-accept");
  const button=document.getElementById("mirage-host-policy-button");
  const status=document.getElementById("mirage-host-policy-status");
  if(!(accept instanceof HTMLInputElement)
      ||!(button instanceof HTMLButtonElement)||!status)return;
  const csrf=root.dataset.csrf||"";
  const url="admin.php?engram_host_policy=1";
  const decode=(s)=>{
    if(typeof s!=="string"||!/^[A-Za-z0-9_-]+$/.test(s))throw Error("INVALID_BASE64");
    const data=atob(s.replace(/-/g,"+").replace(/_/g,"/")+"=".repeat((4-s.length%4)%4));
    return Uint8Array.from(data,c=>c.charCodeAt(0));
  };
  const encode=(buf)=>{
    const array=new Uint8Array(buf);let s="";
    for(let i=0;i<array.length;i+=8192)
      s+=String.fromCharCode(...array.subarray(i,i+8192));
    return btoa(s).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/,"");
  };
  const send=async(payload)=>{
    const resp=await fetch(url,{
      method:"POST",credentials:"same-origin",cache:"no-store",redirect:"error",
      headers:{"Content-Type":"application/json","Accept":"application/json"},
      body:JSON.stringify({csrf,...payload})
    });
    if(resp.status!==200)throw Error("REQUEST_DENIED");
    const body=await resp.json();
    if(body.ok!==true)throw Error("REQUEST_DENIED");
    return body;
  };
  accept.addEventListener("change",()=>{
    button.disabled=!accept.checked;
  });
  button.addEventListener("click",async()=>{
    if(button.disabled||!accept.checked)return;
    button.disabled=true;
    status.textContent="KiCom-Passkey wird geprüft …";
    try{
      if(!window.PublicKeyCredential||!navigator.credentials)throw Error("NO_PASSKEY");
      const started=await send({step:"begin"});
      const options=started.publicKey;
      options.challenge=decode(options.challenge);
      options.allowCredentials=options.allowCredentials.map(item=>({
        ...item,id:decode(item.id)
      }));
      const credential=await navigator.credentials.get({publicKey:options});
      if(!credential||!credential.response)throw Error("NO_ASSERTION");
      const assertion={
        id:credential.id,
        rawId:encode(credential.rawId),
        response:{
          clientDataJSON:encode(credential.response.clientDataJSON),
          authenticatorData:encode(credential.response.authenticatorData),
          signature:encode(credential.response.signature)
        }
      };
      const result=await send({
        step:"confirm",challenge_id:started.challenge_id,
        confirmation:started.confirmation,assertion
      });
      if(result.code!=="SHARED_HOST_POLICY_PREPARED_INACTIVE"
          ||result.memory_enabled!==false||result.oauth_enabled!==false
          ||result.mcp_enabled!==false)throw Error("UNEXPECTED_RESULT");
      status.textContent="Hosting-Entscheidung übernommen. Engram und OAuth bleiben noch deaktiviert.";
    }catch(_e){
      status.textContent="Keine Freigabe abgeschlossen. Bitte Seite neu laden und erneut bestätigen.";
    }
  });
})();
