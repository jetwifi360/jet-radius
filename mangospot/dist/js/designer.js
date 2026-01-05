function DesignerInit() {
  var canvas = document.getElementById('designer-canvas');
  var ctx = canvas.getContext('2d');
  var state = { bg: '', width: 600, height: 350, items: [] };
  var selected = -1;
  var dragging = false;
  var dragDX = 0;
  var dragDY = 0;
  var bgFile = null;
  var editing = false;
  var creatingNew = false;
  var saving = false;
  var imgCache = {};
  function parseLayout(layout){
    var s = layout;
    if(!s) return [];
    if(typeof s !== 'string'){ return Array.isArray(s)? s : (Array.isArray(s.items)? s.items : []); }
    var out = [];
    try { out = JSON.parse(s); } catch(e){ try { out = JSON.parse(String(s).replace(/,\s*}/g,'}')); } catch(e2){ return []; } }
    if(typeof out === 'string'){ try { out = JSON.parse(out); } catch(e3){ out = []; } }
    if(Array.isArray(out)){
      return out.map(function(it){ if(!it.type){ it.type = 'text'; } return it; });
    }
    if(out && Array.isArray(out.items)){
      return out.items.map(function(it){ if(!it.type){ it.type = 'text'; } return it; });
    }
    return [];
  }
  function clamp(it){
    if(!it) return;
    if(it.type === 'image'){
      it.w = Math.max(1, Math.min(parseInt(it.w||canvas.width,10), canvas.width));
      it.h = Math.max(1, Math.min(parseInt(it.h||canvas.height,10), canvas.height));
      it.x = Math.max(0, Math.min(parseInt(it.x||0,10), canvas.width - it.w));
      it.y = Math.max(0, Math.min(parseInt(it.y||0,10), canvas.height - it.h));
    } else {
      it.x = Math.max(0, Math.min(parseInt(it.x||0,10), canvas.width));
      it.y = Math.max(0, Math.min(parseInt(it.y||0,10), canvas.height));
    }
  }

  function getCookie(name){
    var match = document.cookie.match(new RegExp('(?:^|; )'+name.replace(/([.$?*|{}()\[\]\\\/\+^])/g,'\\$1')+'=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }
  function notify(opts){
    if(window.$ && $.toast){ $.toast(opts); }
    else { console[(opts.icon==='error'?'error':'log')](opts.heading+ (opts.text?(': '+opts.text):'')); }
  }

  function draw() {
    ctx.clearRect(0,0,canvas.width, canvas.height);
    drawItems();
  }

  function syncCanvasSize(){
    var parentW = (canvas.parentElement && canvas.parentElement.clientWidth) ? canvas.parentElement.clientWidth : canvas.width;
    canvas.style.maxWidth = '100%';
    canvas.style.width = '100%';
    var h = Math.round(parentW * (canvas.height / canvas.width));
    canvas.style.height = h + 'px';
    canvas.style.display = 'block';
  }

  function drawItems(){
    state.items.forEach(function(it, idx){
      clamp(it);
      if(it.type === 'image'){
        var img = imgCache[it.src];
        if(img){
          ctx.drawImage(img, it.x||0, it.y||0, it.w||canvas.width, it.h||canvas.height);
        } else if(it.src){
          img = new Image();
          img.onload = function(){ imgCache[it.src] = img; draw(); };
          img.src = it.src;
        }
        if(idx === selected){
          ctx.strokeStyle = '#00aaff';
          ctx.strokeRect(it.x||0, (it.y||0), (it.w||canvas.width), (it.h||canvas.height));
        }
      } else {
        var weight = (it.bold ? 'bold ' : '');
        ctx.font = weight + it.size+"px "+it.family;
        ctx.fillStyle = it.color;
        ctx.fillText(it.sample||it.placeholder, it.x, it.y);
        if(idx === selected){
          var w = ctx.measureText(it.sample||it.placeholder).width;
          var h = it.size;
          ctx.strokeStyle = '#00aaff';
          ctx.strokeRect(it.x, it.y - h, w, h);
        }
      }
    });
  }

  function renderToImage(){
    return canvas.toDataURL('image/png');
  }

  function openPreview(){
    var perRow = parseInt(prompt('How many cards per row? (3 or 4)', '3'), 10);
    if(!(perRow===3 || perRow===4)) perRow = 3;
    var dataURL = renderToImage();
    var w = 793; var h = 1122; // A4 at 96dpi approx
    var cardW = canvas.width; var cardH = canvas.height;
    var page = window.open('', 'printPreview');
    var html = `
      <html><head><title>Preview</title>
      <style>
        @page{size:A4;margin:10mm;} body{margin:0;}
        .sheet{width:${w}px;height:${h}px;page-break-after:always;box-sizing:border-box;padding:10mm;}
        .grid{display:grid;grid-template-columns:repeat(${perRow}, 1fr);gap:10px;}
        .card img{width:100%;height:auto;border:1px solid #ccc;}
      </style></head><body>`;
    html += `<div class="sheet"><div class="grid">`;
    var maxPerPage = perRow * Math.floor((h-100) / (cardH + 10));
    var count = Math.max(maxPerPage, perRow); // ensure at least one row
    for(var i=0;i<count;i++){
      html += `<div class="card"><img src="${dataURL}"/></div>`;
    }
    html += `</div></div>`;
    html += `<script>window.onload=function(){window.print();};</script>`;
    html += `</body></html>`;
    page.document.open(); page.document.write(html); page.document.close();
  }

  function showEditor(show){
    var panel = document.getElementById('editor-panel');
    if(panel){ panel.style.display = show ? 'block' : 'none'; }
  }

  function addItem(placeholder){
    var sampleMap = {
      '[label]': 'Label',
      '[profile]': 'Profile',
      '[data]': 'Data',
      '[price]': 'Price',
      '[username]': 'Username',
      '[password]': 'Password',
      '[serial_number]': 'Serial',
      '[qr_code]': 'QR Code'
    };
    var sample = sampleMap[placeholder] || '';
    var it = { type: 'text', placeholder: placeholder, sample: sample, size: 24, family: 'Arial', color: '#000000', x: 50, y: 50 };
    state.items.push(it);
    selected = state.items.length - 1;
    clamp(state.items[selected]);
    syncProps();
    draw();
  }

  function setBackgroundImage(src){
    var bgIdx = -1;
    for(var i=0;i<state.items.length;i++){ if(state.items[i].type==='image' && state.items[i].isBg){ bgIdx = i; break; } }
    var it = { type: 'image', isBg: true, src: src, x: 0, y: 0 };
    if(bgIdx >= 0){ state.items[bgIdx] = it; }
    else { state.items.unshift(it); }
    state.bg = src || state.bg;
    selected = bgIdx >= 0 ? bgIdx : 0;
    clamp(state.items[selected]);
    syncProps();
    draw();
  }

  function pick(x, y){
    var hit = -1;
    for(var i=state.items.length-1;i>=0;i--){
      var it = state.items[i];
      if(it.type === 'image'){
        var wI = it.w || canvas.width;
        var hI = it.h || canvas.height;
        var x0 = it.x || 0; var y0 = it.y || 0;
        if(x >= x0 && x <= x0 + wI && y >= y0 && y <= y0 + hI){ hit = i; break; }
      } else {
        ctx.font = it.size+"px "+it.family;
        var w = ctx.measureText(it.sample||it.placeholder).width;
        var h = it.size;
        if(x >= it.x && x <= it.x + w && y >= it.y - h && y <= it.y){ hit = i; break; }
      }
    }
    return hit;
  }

  function syncProps(){
    var it = state.items[selected];
    var setVal = function(id, val){ var el=document.getElementById(id); if(el) el.value = (val!==undefined? val : ''); };
    setVal('prop-sample', it ? it.sample : '');
    setVal('prop-sample-bottom', it ? it.sample : '');
    setVal('prop-size', it ? it.size : '');
    var fam=document.getElementById('prop-family'); if(fam) fam.value = it ? it.family : 'Arial';
    setVal('prop-color', it ? it.color : '#000000');
    setVal('prop-x', it ? it.x : '');
    setVal('prop-y', it ? it.y : '');
    setVal('prop-size-bottom', it ? it.size : '');
    var famB=document.getElementById('prop-family-bottom'); if(famB) famB.value = it ? it.family : 'Arial';
    setVal('prop-color-bottom', it ? it.color : '#000000');
    setVal('prop-x-bottom', it ? it.x : '');
    setVal('prop-y-bottom', it ? it.y : '');
    setVal('prop-w-bottom', it && it.type==='image' ? (it.w||'') : '');
    setVal('prop-h-bottom', it && it.type==='image' ? (it.h||'') : '');
    var disabled = selected < 0;
    document.querySelectorAll('#prop-panel .form-control, #prop-panel select, #prop-panel button').forEach(function(el){ el.disabled = true; });
    document.querySelectorAll('#prop-panel-bottom .form-control, #prop-panel-bottom button').forEach(function(el){ el.disabled = disabled; });
    var isImg = it && it.type === 'image';
    var textIds = ['prop-size-bottom','prop-family-bottom','prop-color-bottom','prop-bold-bottom'];
    var imgIds = ['prop-w-bottom','prop-h-bottom'];
    textIds.forEach(function(id){ var el=document.getElementById(id); if(el) el.disabled = disabled || isImg; });
    imgIds.forEach(function(id){ var el=document.getElementById(id); if(el) el.disabled = disabled || !isImg; });
  }

  canvas.addEventListener('mousedown', function(e){
    var rect = canvas.getBoundingClientRect();
    var scaleX = canvas.width / rect.width;
    var scaleY = canvas.height / rect.height;
    var x = (e.clientX - rect.left) * scaleX;
    var y = (e.clientY - rect.top) * scaleY;
    selected = pick(x, y);
    if(selected >= 0){
      var it = state.items[selected];
      ctx.font = it.size+"px "+it.family;
      var w = ctx.measureText(it.sample||it.placeholder).width;
      var h = it.size;
      dragDX = x - it.x;
      dragDY = y - it.y;
      dragging = true;
      syncProps();
      draw();
    } else {
      dragging = false;
      syncProps();
      draw();
    }
  });

  canvas.addEventListener('mousemove', function(e){
    if(!dragging || selected < 0) return;
    var rect = canvas.getBoundingClientRect();
    var scaleX = canvas.width / rect.width;
    var scaleY = canvas.height / rect.height;
    var x = (e.clientX - rect.left) * scaleX;
    var y = (e.clientY - rect.top) * scaleY;
    var it = state.items[selected];
    it.x = Math.round(x - dragDX);
    it.y = Math.round(y - dragDY);
    clamp(it);
    syncProps();
    draw();
  });

  window.addEventListener('mouseup', function(){ dragging = false; });
  canvas.addEventListener('mouseleave', function(){ dragging = false; });

  // Double-click to edit text directly on canvas
  var lastClickTime = 0;
  canvas.addEventListener('click', function(e) {
    var currentTime = new Date().getTime();
    var clickDelay = currentTime - lastClickTime;
    
    if (clickDelay < 300 && clickDelay > 0) {
      // Double-click detected
      var rect = canvas.getBoundingClientRect();
      var scaleX = canvas.width / rect.width;
      var scaleY = canvas.height / rect.height;
      var x = (e.clientX - rect.left) * scaleX;
      var y = (e.clientY - rect.top) * scaleY;
      
      var clickedItemIndex = pick(x, y);
      if (clickedItemIndex >= 0 && state.items[clickedItemIndex].type === 'text') {
        selected = clickedItemIndex;
        startDirectTextEditing();
        syncProps();
        draw();
      }
    }
    lastClickTime = currentTime;
  });

  function startDirectTextEditing() {
    if (selected < 0 || state.items[selected].type !== 'text') return;
    
    var it = state.items[selected];
    
    // Create overlay input for direct editing
    var overlayInput = document.getElementById('canvas-text-input');
    if (!overlayInput) {
      overlayInput = document.createElement('textarea');
      overlayInput.id = 'canvas-text-input';
      overlayInput.style.position = 'absolute';
      overlayInput.style.background = 'rgba(255, 255, 255, 0.9)';
      overlayInput.style.border = '2px solid #00aaff';
      overlayInput.style.borderRadius = '4px';
      overlayInput.style.padding = '4px';
      overlayInput.style.fontFamily = it.family;
      overlayInput.style.fontSize = it.size + 'px';
      overlayInput.style.color = it.color;
      overlayInput.style.zIndex = '1000';
      overlayInput.style.resize = 'none';
      overlayInput.style.overflow = 'hidden';
      document.body.appendChild(overlayInput);
      
      overlayInput.addEventListener('blur', function() {
        it.sample = this.value;
        this.parentNode.removeChild(this);
        draw();
      });
      
      overlayInput.addEventListener('input', function() {
        // Auto-resize the textarea to fit content
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight + 10) + 'px';
      });
      
      overlayInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.shiftKey) {
          e.preventDefault();
          // Shift+Enter: Insert newline at cursor position for paragraph breaks
          var cursorPos = this.selectionStart;
          this.value = this.value.substring(0, cursorPos) + '\n' + this.value.substring(this.selectionEnd);
          this.selectionStart = this.selectionEnd = cursorPos + 1;
          // Auto-resize the textarea to fit content
          this.style.height = 'auto';
          this.style.height = (this.scrollHeight + 10) + 'px';
        } else if (e.key === 'Enter' && !e.shiftKey) {
          // Enter: Submit and finish editing (blur the input)
          e.preventDefault();
          this.blur();
        } else if (e.key === 'Escape') {
          this.blur();
        }
      });
    }
    
    // Position and size the input overlay
    var canvasRect = canvas.getBoundingClientRect();
    var scaleX = canvas.width / canvasRect.width;
    var scaleY = canvas.height / canvasRect.height;
    
    ctx.font = it.size + 'px ' + it.family;
    var textWidth = ctx.measureText(it.sample || it.placeholder).width;
    var textHeight = it.size;
    
    overlayInput.value = it.sample || '';
    overlayInput.style.fontFamily = it.family;
    overlayInput.style.fontSize = it.size + 'px';
    overlayInput.style.color = it.color;
    
    overlayInput.style.width = (textWidth / scaleX + 20) + 'px';
    overlayInput.style.height = (textHeight / scaleY + 10) + 'px';
    overlayInput.style.left = (canvasRect.left + it.x / scaleX - 10) + 'px';
    overlayInput.style.top = (canvasRect.top + it.y / scaleY - textHeight / scaleY - 5) + 'px';
    
    overlayInput.focus();
    overlayInput.select();
  }

  document.addEventListener('keydown', function(e){
    if(selected < 0) return;
    var it = state.items[selected];
    var step = e.shiftKey ? 5 : 1;
    if(e.key === 'ArrowLeft'){ it.x -= step; e.preventDefault(); }
    else if(e.key === 'ArrowRight'){ it.x += step; e.preventDefault(); }
    else if(e.key === 'ArrowUp'){ it.y -= step; e.preventDefault(); }
    else if(e.key === 'ArrowDown'){ it.y += step; e.preventDefault(); }
    syncProps();
    draw();
  });

  
  document.addEventListener('click', function(e){
    var el = e.target && e.target.closest ? e.target.closest('.tool-add') : null;
    if(el){ e.preventDefault(); e.stopPropagation(); addItem(el.dataset.placeholder); }
  });

  function ensureBottomPanel(){
    var panel = document.getElementById('prop-panel-bottom');
    if(!panel){
      var container = document.querySelector('.container');
      if(!container) return;
      var html = '';
      html += '<div class="row mt-3">';
      html += '<div class="col-md-12">';
      html += '<div class="card">';
      html += '<div class="card-header">Selected Item</div>';
      html += '<div class="card-body">';
      html += '<div id="prop-panel-bottom" class="row g-2">';
      html += '<div class="col-md-3"><input type="text" id="prop-sample-bottom" class="form-control" placeholder="Sample Value" /></div>';
      html += '<div class="col-md-2"><input type="number" id="prop-size-bottom" class="form-control" min="8" max="96" placeholder="Font Size" /></div>';
      html += '<div class="col-md-2"><select id="prop-family-bottom" class="form-control"><option>Arial</option><option>Verdana</option><option>Tahoma</option><option>Courier New</option></select></div>';
      html += '<div class="col-md-2"><input type="color" id="prop-color-bottom" class="form-control" /></div>';
      html += '<div class="col-md-1"><input type="number" id="prop-x-bottom" class="form-control" placeholder="X" /></div>';
      html += '<div class="col-md-1"><input type="number" id="prop-y-bottom" class="form-control" placeholder="Y" /></div>';
      html += '<div class="col-md-1"><button class="btn btn-outline-danger btn-sm w-100" id="remove-item-bottom"><i class="fa fa-trash"></i></button></div>';
      html += '</div></div></div></div></div>';
      container.insertAdjacentHTML('beforeend', html);
      panel = document.getElementById('prop-panel-bottom');
    }
    var needIds = ['prop-size-bottom','prop-family-bottom','prop-color-bottom','prop-x-bottom','prop-y-bottom','remove-item-bottom'];
    needIds.forEach(function(id){ if(!document.getElementById(id)){ var col = document.createElement('div'); col.className='col-md-2'; var inner=''; if(id==='prop-size-bottom'){ inner='<input type="number" id="prop-size-bottom" class="form-control" min="8" max="96" placeholder="Font Size" />'; col.className='col-md-2'; }
      else if(id==='prop-family-bottom'){ inner='<select id="prop-family-bottom" class="form-control"><option>Arial</option><option>Verdana</option><option>Tahoma</option><option>Courier New</option></select>'; col.className='col-md-2'; }
      else if(id==='prop-color-bottom'){ inner='<input type="color" id="prop-color-bottom" class="form-control" />'; col.className='col-md-2'; }
      else if(id==='prop-x-bottom'){ inner='<input type="number" id="prop-x-bottom" class="form-control" placeholder="X" />'; col.className='col-md-1'; }
      else if(id==='prop-y-bottom'){ inner='<input type="number" id="prop-y-bottom" class="form-control" placeholder="Y" />'; col.className='col-md-1'; }
      else if(id==='remove-item-bottom'){ inner='<button class="btn btn-outline-danger btn-sm w-100" id="remove-item-bottom"><i class="fa fa-trash"></i></button>'; col.className='col-md-1'; }
      col.innerHTML = inner; panel.appendChild(col); } });
  }
  ensureBottomPanel();

  var startBtn = document.getElementById('start-new');
  if(startBtn){
    startBtn.addEventListener('click', function(){
      state.items = [];
      state.bg = '';
      bgFile = null;
      selected = -1;
      editing = true;
      creatingNew = true;
      var idEl = document.getElementById('tpl-id'); if(idEl) idEl.value = '';
      var nameEl = document.getElementById('tpl-name'); if(nameEl) nameEl.value = '';
      var sel = document.getElementById('tpl-list'); if(sel){
        var opt = document.createElement('option'); opt.value=''; opt.textContent='New Template'; opt.selected=true; sel.insertBefore(opt, sel.firstChild);
      }
      showEditor(true);
      syncProps();
      syncCanvasSize();
      draw();
    });
  }
  document.addEventListener('click', function(e){
    if(e.target && e.target.id === 'start-new'){
      state.items = [];
      state.bg = '';
      bgFile = null;
      selected = -1;
      editing = true;
      creatingNew = true;
      var idEl = document.getElementById('tpl-id'); if(idEl) idEl.value = '';
      var nameEl = document.getElementById('tpl-name'); if(nameEl) nameEl.value = '';
      var sel = document.getElementById('tpl-list'); if(sel){
        var opt = document.createElement('option'); opt.value=''; opt.textContent='New Template'; opt.selected=true; sel.insertBefore(opt, sel.firstChild);
      }
      showEditor(true);
      syncProps();
      syncCanvasSize();
      draw();
    }
  });

  ;['prop-sample','prop-size','prop-family','prop-color','prop-x','prop-y','prop-bold-bottom'].forEach(function(id){
    var el = document.getElementById(id); if(!el) return;
    var evt = (id==='prop-family'? 'change': (id==='prop-bold-bottom' ? 'change' : 'input'));
    el.addEventListener(evt, function(){
      if(selected<0) return;
      var it = state.items[selected];
      if(id==='prop-sample'){ it.sample = this.value; }
      else if(id==='prop-size'){ if(it.type!=='image') it.size = parseInt(this.value||24,10); }
      else if(id==='prop-family'){ if(it.type!=='image') it.family = this.value; }
      else if(id==='prop-color'){ if(it.type!=='image') it.color = this.value; }
      else if(id==='prop-x'){ it.x = parseInt(this.value||0,10); }
      else if(id==='prop-y'){ it.y = parseInt(this.value||0,10); }
      else if(id==='prop-bold-bottom'){ if(it.type!=='image') it.bold = !!this.checked; }
      clamp(it);
      draw();
    });
  });
  var sampleBottom = document.getElementById('prop-sample-bottom');
  if(sampleBottom){ 
    sampleBottom.addEventListener('input', function(){ if(selected<0) return; var it = state.items[selected]; it.sample = this.value; draw(); }); 
    sampleBottom.addEventListener('keydown', function(e){
      if(e.key === 'Enter') {
        e.preventDefault();
        if(selected < 0) return;
        var it = state.items[selected];
        // Insert newline at cursor position for proper paragraph breaks
        var cursorPos = this.selectionStart;
        var textBefore = this.value.substring(0, cursorPos);
        var textAfter = this.value.substring(cursorPos);
        it.sample = textBefore + '\n' + textAfter;
        this.value = it.sample;
        // Move cursor to position after the newline
        this.selectionStart = this.selectionEnd = cursorPos + 1;
        draw();
      }
    });
  }
  var sizeBottom = document.getElementById('prop-size-bottom');
  if(sizeBottom){ sizeBottom.addEventListener('input', function(){ if(selected<0) return; var it = state.items[selected]; if(it.type!=='image'){ it.size = parseInt(this.value||24,10); } draw(); }); }
  var famBottom = document.getElementById('prop-family-bottom');
  if(famBottom){ famBottom.addEventListener('change', function(){ if(selected<0) return; var it = state.items[selected]; if(it.type!=='image'){ it.family = this.value; } draw(); }); }
  var colorBottom = document.getElementById('prop-color-bottom');
  if(colorBottom){ colorBottom.addEventListener('input', function(){ if(selected<0) return; var it = state.items[selected]; if(it.type!=='image'){ it.color = this.value; } draw(); }); }
  var xBottom = document.getElementById('prop-x-bottom');
  if(xBottom){ xBottom.addEventListener('input', function(){ if(selected<0) return; var it = state.items[selected]; it.x = parseInt(this.value||0,10); draw(); }); }
  var yBottom = document.getElementById('prop-y-bottom');
  if(yBottom){ yBottom.addEventListener('input', function(){ if(selected<0) return; var it = state.items[selected]; it.y = parseInt(this.value||0,10); draw(); }); }
  var wBottom = document.getElementById('prop-w-bottom');
  if(wBottom){ wBottom.addEventListener('input', function(){ if(selected<0) return; var it = state.items[selected]; if(it.type==='image'){ it.w = parseInt(this.value||canvas.width,10); clamp(it); } draw(); }); }
  var hBottom = document.getElementById('prop-h-bottom');
  if(hBottom){ hBottom.addEventListener('input', function(){ if(selected<0) return; var it = state.items[selected]; if(it.type==='image'){ it.h = parseInt(this.value||canvas.height,10); clamp(it); } draw(); }); }
  var rmBottom = document.getElementById('remove-item-bottom');
  if(rmBottom){ rmBottom.addEventListener('click', function(e){ e.preventDefault(); if(selected<0){ notify({ heading:'Remove', text:'No item selected', position:'top-right', icon:'error'}); return; } state.items.splice(selected,1); selected = -1; syncProps(); draw(); }); }

  var rm = document.getElementById('remove-item');
  if(rm){ rm.addEventListener('click', function(e){ e.preventDefault(); if(selected<0){ notify({ heading:'Remove', text:'No item selected', position:'top-right', icon:'error'}); return; } state.items.splice(selected,1); selected = -1; syncProps(); draw(); }); }

  document.getElementById('bg-file').addEventListener('change', function(e){
    var file = e.target.files[0];
    if(!file) return;
    bgFile = file;
    var reader = new FileReader();
    reader.onload = function(){ setBackgroundImage(reader.result); };
    reader.readAsDataURL(file);
  });

  function postDesigner(body){
    var h = { 'Api': getCookie('BSK_API'), 'Key': getCookie('BSK_KEY'), 'Accept':'application/json' };
    function parseTextToJson(t){
      if(!t) return { status:false };
      var s = String(t).trim();
      var i = s.indexOf('{'); var j = s.lastIndexOf('}');
      if(i > 0 || j > 0) s = s.slice(i, j+1);
      try{ return JSON.parse(s); } catch(e){ try{ return JSON.parse(s.replace(/,\s*}/g,'}')); } catch(e2){ return { status:false }; } }
    }
    
    // Debug: log what we're sending
    console.log('Sending to server:', './api/index.php?pages=designer');
    var formDataEntries = [];
    for (var pair of body.entries()) {
      formDataEntries.push(pair[0] + ': ' + (pair[0] === 'layout' ? '[layout data]' : pair[1]));
    }
    console.log('Form data:', formDataEntries.join(', '));
    
    return fetch('./api/index.php?pages=designer', { method: 'POST', headers: h, body: body })
      .then(function(r){ 
        console.log('Server response status:', r.status, r.statusText);
        if(!r.ok) throw new Error('HTTP '+r.status); 
        return r.text(); 
      })
      .then(function(text){
        console.log('Raw server response:', text);
        return parseTextToJson(text);
      })
      .catch(function(err){ 
        throw err;
      });
  }
  function getDesigner(query){
    var h = { 'Api': getCookie('BSK_API'), 'Key': getCookie('BSK_KEY'), 'Accept':'application/json' };
    function parseTextToJson(t){
      if(!t) return { status:false, data:[] };
      var s = String(t).trim();
      var i = s.indexOf('{'); var j = s.lastIndexOf('}');
      if(i > 0 || j > 0) s = s.slice(i, j+1);
      try{ return JSON.parse(s); } catch(e){ try{ return JSON.parse(s.replace(/,\s*}/g,'}')); } catch(e2){ return { status:false, data:[] }; } }
    }
    var q = query ? (query.charAt(0) === '?' ? '&'+query.slice(1) : query) : '';
    var cb = '&_cb='+(Date.now());
    return fetch('./api/index.php?pages=designer'+q+cb, { headers: h })
      .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
      .then(parseTextToJson)
      ;
  }

  function rowsFrom(res){
    if(Array.isArray(res)) return res;
    if(res && Array.isArray(res.data)) return res.data;
    if(res && res.data && Array.isArray(res.data.data)) return res.data.data;
    return [];
  }

  function getDesignerList(){
    var h = { 'Api': getCookie('BSK_API'), 'Key': getCookie('BSK_KEY'), 'Accept':'application/json' };
    function parse(t){ if(!t) return {}; var s=String(t).trim(); var i=s.indexOf('{'); var j=s.lastIndexOf('}'); if(i>0||j>0) s=s.slice(i,j+1); try{ return JSON.parse(s); } catch(e){ try{ return JSON.parse(s.replace(/,\s*}/g,'}')); } catch(e2){ return {}; } } }
    var cb = '&_cb='+(Date.now());
    return fetch('./api/index.php?pages=designer&list'+cb, { headers: h })
      .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
      .then(parse)
      .then(rowsFrom)
      ;
  }

  function loadTemplate(id){
    if(!id) return Promise.resolve();
    return getDesigner('?detail='+id)
      .then(function(res){ if(res && res.status && res.data){ var t=res.data; if(Array.isArray(t)){ t = t[0] || {}; } if(t.data){ t = t.data; } var idEl=document.getElementById('tpl-id'); if(idEl) idEl.value = t.id; var nameEl=document.getElementById('tpl-name'); if(nameEl) nameEl.value = t.name; canvas.width = t.width; canvas.height = t.height; syncCanvasSize(); state.bg = (t.background? ((String(t.background).indexOf('./')===0)? t.background : ('./'+t.background)) : ''); bgFile = null; state.items = parseLayout(t.layout||'[]');
        var hasBg = false; for(var i=0;i<state.items.length;i++){ if(state.items[i].type==='image' && state.items[i].isBg){ hasBg = true; break; } }
        if(state.bg && !hasBg){ setBackgroundImage(state.bg); }
        selected = -1; editing = true; showEditor(true); syncProps(); draw(); } });
  }

  function populateTemplates(){
    var sel = document.getElementById('tpl-list'); if(sel){ sel.innerHTML=''; var optL=document.createElement('option'); optL.value=''; optL.textContent='Loading...'; optL.disabled=true; optL.selected=true; sel.appendChild(optL); }
    return getDesignerList().then(function(rows){
      var sel = document.getElementById('tpl-list'); if(!sel) return;
      sel.innerHTML = '';
      if(rows.length === 0){
        var opt=document.createElement('option'); opt.value=''; opt.textContent='No templates'; opt.disabled=true; opt.selected=true; sel.appendChild(opt); return; }
      rows.forEach(function(t){ if(!t) return; var opt=document.createElement('option'); opt.value=t.id; opt.textContent=t.name || ('Template '+t.id); sel.appendChild(opt); });
      var curId = (document.getElementById('tpl-id') && document.getElementById('tpl-id').value) || '';
      if(creatingNew || !curId){
        var optN=document.createElement('option'); optN.value=''; optN.textContent='New Template'; optN.selected=true; sel.insertBefore(optN, sel.firstChild);
      } else {
        sel.value = curId;
        if(!saving){ loadTemplate(sel.value); }
      }
    }).catch(function(err){ var sel=document.getElementById('tpl-list'); if(sel){ sel.innerHTML=''; var opt=document.createElement('option'); opt.value=''; opt.textContent='Load failed'; opt.disabled=true; opt.selected=true; sel.appendChild(opt); } notify({ heading:'Request failed', text:String(err), position:'top-right', icon:'error'}); });
  }

  document.getElementById('save-tpl').addEventListener('click', function(){
    var form = new FormData();
    var curId = (document.getElementById('tpl-id').value||'');
    var curName = (document.getElementById('tpl-name').value||'');
    
    // Validate input
    if(!curName && !curId){
      curName = prompt('Enter template name to save:', 'My Template');
      if(!curName){ notify({ heading:'Cancelled', text:'Name required', position:'top-right', icon:'error'}); return; }
      var nameEl = document.getElementById('tpl-name'); if(nameEl) nameEl.value = curName;
    }
    
    // Store current state for potential restoration
    var currentState = {
      items: JSON.parse(JSON.stringify(state.items)),
      bg: state.bg,
      width: canvas.width,
      height: canvas.height
    };
    
    form.append('id', curId);
    form.append('name', curName || ('Template '+Date.now()));
    form.append('width', canvas.width);
    form.append('height', canvas.height);
    form.append('layout', JSON.stringify(state.items));
    
    if(bgFile){ 
      form.append('background', bgFile); 
    } else if(state.bg && state.bg !== ''){ 
      form.append('background', state.bg); 
    }
    
    saving = true;
    
    // Enhanced save function with better error handling and state management
    postDesigner(form)
      .then(function(res){ 
        console.log('Server response:', JSON.stringify(res, null, 2));
        if(res && res.status){
          // Extract saved ID reliably - server returns {status:true, id:123, background:'path'}
          var savedId = res.id || curId;
          
          // Update UI with saved data
          var idEl = document.getElementById('tpl-id');
          if(idEl) idEl.value = savedId;
          creatingNew = false;
          
          // Handle background path - server returns background directly in response
          if(res.background){
            var bgPath = res.background;
            state.bg = (bgPath && bgPath.indexOf('./')===0) ? bgPath : ('./'+bgPath);
            bgFile = null;
          }
          
          // Check debug info to see where it was actually saved
          var saveLocation = 'database';
          if(res.debug){
            saveLocation = res.debug.db ? 'database' : (res.debug.fs ? 'filesystem' : 'unknown');
          }
          
          notify({ heading:'Success', text:'Template saved successfully (' + saveLocation + ')', position:'top-right', icon:'success'});
          
          // Update template list and refresh after short delay
          var sel = document.getElementById('tpl-list');
          if(sel){ 
            sel.value = savedId;
            // Refresh template list but don't reload current template immediately
            setTimeout(function() {
              populateTemplates().then(function() {
                saving = false;
                // Keep current state visible instead of reloading
                draw();
              });
            }, 500);
          } else {
            saving = false;
          }
          
        } else {
          // Save failed - restore previous state
          state.items = currentState.items;
          state.bg = currentState.bg;
          canvas.width = currentState.width;
          canvas.height = currentState.height;
          saving = false;
          notify({ heading:'Error', text:'Save failed - no status returned', position:'top-right', icon:'error'});
        }
      })
      .catch(function(err){
        // Save failed - restore previous state
        state.items = currentState.items;
        state.bg = currentState.bg;
        canvas.width = currentState.width;
        canvas.height = currentState.height;
        saving = false;
        notify({ heading:'Request Failed', text:'Save operation failed: ' + err.message, position:'top-right', icon:'error'});
        draw();
      });
  });

  var startBtn2 = document.getElementById('start-new');
  if(startBtn2){ startBtn2.insertAdjacentHTML('afterend', '<button class="btn btn-secondary ms-2" id="preview-tpl"><i class="fa fa-print"></i> Preview</button>'); }
  document.addEventListener('click', function(e){ if(e.target && e.target.id==='preview-tpl'){ e.preventDefault(); openPreview(); } });

  // Dedicated update function for existing templates
  document.getElementById('update-tpl').addEventListener('click', function(){
    var id = (document.getElementById('tpl-id').value||'');
    if(!id){
      notify({ heading:'Update', text:'No template selected for update', position:'top-right', icon:'error'});
      return;
    }
    
    var form = new FormData();
    var curName = (document.getElementById('tpl-name').value||'');
    
    // Validate input
    if(!curName){
      curName = prompt('Enter template name to update:', 'My Template');
      if(!curName){ 
        notify({ heading:'Cancelled', text:'Name required', position:'top-right', icon:'error'}); 
        return; 
      }
      var nameEl = document.getElementById('tpl-name'); 
      if(nameEl) nameEl.value = curName;
    }
    
    // Store current state for potential restoration
    var currentState = {
      items: JSON.parse(JSON.stringify(state.items)),
      bg: state.bg,
      width: canvas.width,
      height: canvas.height
    };
    
    form.append('id', id);
    form.append('name', curName || ('Template '+Date.now()));
    form.append('width', canvas.width);
    form.append('height', canvas.height);
    form.append('layout', JSON.stringify(state.items));
    
    if(bgFile){ 
      form.append('background', bgFile); 
    } else if(state.bg && state.bg !== ''){ 
      form.append('background', state.bg); 
    }
    
    saving = true;
    
    // Enhanced update function with better error handling
    postDesigner(form)
      .then(function(res){ 
        console.log('Update response:', JSON.stringify(res, null, 2));
        if(res && res.status){
          // Handle background path
          if(res.background){
            var bgPath = res.background;
            state.bg = (bgPath && bgPath.indexOf('./')===0) ? bgPath : ('./'+bgPath);
            bgFile = null;
          }
          
          // Check debug info to see where it was actually saved
          var saveLocation = 'database';
          if(res.debug){
            saveLocation = res.debug.db ? 'database' : (res.debug.fs ? 'filesystem' : 'unknown');
          }
          
          notify({ heading:'Update Success', text:'Template updated successfully (' + saveLocation + ')', position:'top-right', icon:'success'});
          
          // Refresh template list but keep current state
          setTimeout(function() {
            populateTemplates().then(function() {
              saving = false;
              draw();
            });
          }, 500);
          
        } else {
          // Update failed - restore previous state
          state.items = currentState.items;
          state.bg = currentState.bg;
          canvas.width = currentState.width;
          canvas.height = currentState.height;
          saving = false;
          notify({ 
            heading:'Update Failed', 
            text: res.message || 'Update failed - no status returned', 
            position:'top-right', 
            icon:'error'
          });
        }
      })
      .catch(function(err){
        // Update failed - restore previous state
        state.items = currentState.items;
        state.bg = currentState.bg;
        canvas.width = currentState.width;
        canvas.height = currentState.height;
        saving = false;
        notify({ 
          heading:'Update Request Failed', 
          text:'Update operation failed: ' + err.message, 
          position:'top-right', 
          icon:'error'
        });
        draw();
      });
  });

  document.getElementById('delete-tpl').addEventListener('click', function(){
    var id = (document.getElementById('tpl-id').value||'');
    if(!id){ notify({ heading:'Delete', text:'No template selected', position:'top-right', icon:'error'}); return; }
    if(!confirm('Delete this template?')) return;
    var form = new FormData(); form.append('delete', id);
    postDesigner(form).then(function(res){ if(res.status){ notify({ heading:'Deleted', text:'Template removed', position:'top-right', icon:'success'}); document.getElementById('tpl-id').value=''; document.getElementById('tpl-name').value=''; state.items=[]; state.bg=''; selected=-1; editing=false; syncProps(); draw(); populateTemplates(); } else { notify({ heading:'Error', text:'Delete failed', position:'top-right', icon:'error'}); } })
      .catch(function(err){ notify({ heading:'Request failed', text:String(err), position:'top-right', icon:'error'}); });
  });

  populateTemplates();
  var tplSel = document.getElementById('tpl-list'); if(tplSel){ tplSel.addEventListener('focus', function(){ if(saving) return; populateTemplates(); }); }

  document.getElementById('tpl-list').addEventListener('change', function(){
    if(saving) return;
    var id = this.value; if(!id) return; loadTemplate(id).catch(function(err){ notify({ heading:'Request failed', text:String(err), position:'top-right', icon:'error'}); });
  });

  syncProps();
  showEditor(false);
  syncCanvasSize();
  draw();
}

if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', DesignerInit);
} else {
  DesignerInit();
  window.addEventListener('resize', function(){ try{ syncCanvasSize(); } catch(e){} });
}