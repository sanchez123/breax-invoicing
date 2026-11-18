<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script>
(function(){
  var box=document.getElementById('qrCode'),wrap=document.getElementById('qrWrap');
  try{
    var qr=qrcode(0,'M');qr.addData(box.getAttribute('data-qr'));qr.make();
    var n=qr.getModuleCount(),d='';
    for(var r=0;r<n;r++)for(var c=0;c<n;c++)if(qr.isDark(r,c))d+='M'+c+' '+r+'h1v1h-1z';
    box.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+n+' '+n+'" shape-rendering="crispEdges" role="img" aria-label="QR code for this invoice"><path d="'+d+'" fill="#141d38"/></svg>';
  }catch(e){wrap.style.display='none';}
})();
</script>
