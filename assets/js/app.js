let allRows=[], filteredRows=[], currentPage=1;
let sortCol='', sortDir='asc';
let selectedItems = new Set();
let moveTargetItem = null, moveIsBulk = false;
let currentPreviewFile = null, currentRotation = 0;
let currentEditItem  = {name:'',isFolder:false};
let currentDeleteItem= {name:'',isFolder:false};
let ctxTarget = null; // row currently targeted by context menu
let imageRows = [];   // list of image-previewable rows
let lbIndex   = -1;   // current lightbox index

// ══ INIT ══
document.addEventListener('DOMContentLoaded', () => {
    allRows = Array.from(document.querySelectorAll('#fileTableWrap tbody tr'));
    filteredRows = [...allRows];
    imageRows = allRows.filter(r => ['jpg','jpeg','png','gif','bmp','webp','svg'].includes(r.dataset.type));
    updateSearchCount();
    // Default sort: folders first, newest date
    sortCol='date'; sortDir='desc';
    const thDate = document.querySelectorAll('th.sortable')[3];
    if(thDate){thDate.classList.add('sort-desc');thDate.style.color='var(--gold)';}
    _sortArr(allRows,'date','desc');
    _sortArr(filteredRows,'date','desc');
    const tbody=document.querySelector('#fileTableWrap tbody');
    if(tbody) allRows.forEach(r=>tbody.appendChild(r));
    // Restore theme
    if(localStorage.getItem('storageTheme')==='light'){
        document.body.classList.add('light');
        const ic=document.querySelector('#btnTheme i');
        if(ic){ic.className='fas fa-moon';}
    }
    // Restore per-page
    const savedPP = localStorage.getItem('storagePerPage') || '20';
    ITEMS_PER_PAGE = parseInt(savedPP) || 0;
    const ppSel = document.getElementById('perPageSelect');
    if(ppSel) ppSel.value = savedPP;
    renderPage();
    setView(localStorage.getItem('storageView')||'list');
    initDragDrop();
    initContextMenu();
    initKeyboard();
    initPasteUpload();
    initRowOpen();
});

// ══ KLIK BARIS/KARTU = LANGSUNG BUKA ══
// Folder → masuk ke dalamnya · File → preview.
// Klik di tombol aksi / checkbox / link tetap jalan seperti biasa.
function initRowOpen(){
    const tbody=document.querySelector('#fileTableWrap tbody');
    if(!tbody) return;
    tbody.addEventListener('click', e=>{
        if(e.target.closest('.actions,button,a,input,select,label')) return;
        const tr=e.target.closest('tr');
        if(!tr || !tr.dataset.name) return;
        if(window.getSelection && String(window.getSelection())) return; // lagi nge-blok teks
        const folder=new URLSearchParams(window.location.search).get('folder')||'';
        if(tr.dataset.isFolder==='1'){
            location.href='dashboard.php?folder='+(folder?folder+'/':'')+encodeURIComponent(tr.dataset.name);
        } else {
            viewFile(tr.dataset.name, tr.dataset.type);
        }
    });
}

// ══ MOBILE MENU (hamburger) ══
function toggleMobileMenu(e){
    if(e) e.stopPropagation();
    const menu=document.getElementById('mobileMenu');
    const bd=document.getElementById('mobileMenuBackdrop');
    const burger=document.getElementById('navBurger');
    const open=menu.classList.toggle('open');
    bd.classList.toggle('open',open);
    burger.classList.toggle('open',open);
    document.body.style.overflow=open?'hidden':'';
}
function closeMobileMenu(){
    document.getElementById('mobileMenu')?.classList.remove('open');
    document.getElementById('mobileMenuBackdrop')?.classList.remove('open');
    document.getElementById('navBurger')?.classList.remove('open');
    document.body.style.overflow='';
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMobileMenu();});

// ══ MODAL HELPERS ══
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ALL_MODALS.forEach(closeModal);
        document.getElementById('ctxMenu').classList.remove('open');
    }
});
const ALL_MODALS = ['previewModal','editModal','deleteModal','moveModal','changePwModal','logModal','trashModal','batchRenameModal','shareModal','folderColorModal','editFileModal','statsModal','confirmModal','globalSearchModal'];
ALL_MODALS.forEach(id => {
    const el = document.getElementById(id);
    if(el) el.addEventListener('click', function(e) { if(e.target===this) closeModal(id); });
});
// Auto session extend every 10 minutes
setInterval(()=>{
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/session.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.send('csrf_token='+encodeURIComponent(CSRF_TOKEN));
}, 10*60*1000);

// ══ CREATE FOLDER ══
function createFolderAjax() {
    const name = document.getElementById('folderName').value.trim();
    if (!name) { showFolderMsg('Folder name cannot be empty','error'); return; }
    if (!/^[a-zA-Z0-9_-]+$/.test(name)) { showFolderMsg('Only letters, numbers, _ and - allowed','error'); return; }
    const folder = new URLSearchParams(window.location.search).get('folder')||'';
    const xhr = new XMLHttpRequest();
    xhr.open('POST','api/create_folder.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload = () => {
        const r = JSON.parse(xhr.responseText);
        if (r.success) { showFolderMsg('Folder created! Refreshing...','success'); setTimeout(()=>location.reload(),900); }
        else showFolderMsg(r.message,'error');
    };
    xhr.send('folder_name='+encodeURIComponent(name)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showFolderMsg(msg,type) {
    const el = document.getElementById('folderMessage');
    el.className = 'msg-box '+(type==='success'?'msg-success':'msg-error');
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;
}

// ══ UPLOAD ══
let selectedFiles=[], uploadInProgress=false;
const dropArea  = document.getElementById('dropArea');
const fileInput = document.getElementById('file_upload');
dropArea.addEventListener('dragover',  e=>{e.preventDefault();dropArea.classList.add('active');});
dropArea.addEventListener('dragleave', ()=>dropArea.classList.remove('active'));
dropArea.addEventListener('drop', e=>{e.preventDefault();dropArea.classList.remove('active');if(e.dataTransfer.files.length)handleFiles(e.dataTransfer.files);});
dropArea.addEventListener('click', ()=>fileInput.click());
fileInput.addEventListener('change', e=>{if(e.target.files.length)handleFiles(e.target.files);});
function handleFiles(files){
    const incoming=Array.from(files);
    const rejected=[];
    const accepted=incoming.filter(f=>{
        const ext=(f.name.split('.').pop()||'').toLowerCase();
        if(typeof ALLOWED_EXTS!=='undefined'&&!ALLOWED_EXTS.includes(ext)){rejected.push(`${f.name} (.${ext} tidak diizinkan)`);return false;}
        if(typeof MAX_FILE_SIZE!=='undefined'&&f.size>MAX_FILE_SIZE){rejected.push(`${f.name} (melebihi ${formatBytes(MAX_FILE_SIZE)})`);return false;}
        return true;
    });
    if(rejected.length)showToast(rejected.length+' file ditolak: '+rejected[0]+(rejected.length>1?` +${rejected.length-1} lainnya`:''),'warn');
    // Gabung dengan pilihan sebelumnya, skip duplikat nama+ukuran
    accepted.forEach(f=>{
        if(!selectedFiles.some(s=>s.name===f.name&&s.size===f.size))selectedFiles.push(f);
    });
    updateFileList();
}
function updateFileList(){
    const wrap=document.getElementById('selectedFiles'), list=document.getElementById('filesList');
    if(!selectedFiles.length){wrap.style.display='none';return;}
    wrap.style.display='block';
    document.getElementById('totalFiles').textContent=selectedFiles.length;
    document.getElementById('totalSize').textContent=formatBytes(selectedFiles.reduce((s,f)=>s+f.size,0));
    list.innerHTML='';
    selectedFiles.forEach((f,i)=>{
        const c=document.createElement('div'); c.className='file-chip';
        c.innerHTML=`<span class="file-chip-name" title="${f.name}">${f.name}</span>
            <span style="display:flex;align-items:center;gap:7px;">
                <span style="color:var(--text-muted);font-size:11px;">${formatBytes(f.size)}</span>
                <button onclick="removeFile(${i})" class="btn btn-ghost btn-sm" style="padding:2px 6px;"><i class="fas fa-times"></i></button>
            </span>`;
        list.appendChild(c);
    });
}
function removeFile(i){selectedFiles.splice(i,1);const dt=new DataTransfer();selectedFiles.forEach(f=>dt.items.add(f));fileInput.files=dt.files;updateFileList();}
function clearFiles(){selectedFiles=[];fileInput.value='';updateFileList();document.getElementById('uploadProgress').style.display='none';}
function formatBytes(b,d=2){if(!b)return'0 B';const k=1024,s=['B','KB','MB','GB'],i=Math.floor(Math.log(b)/Math.log(k));return parseFloat((b/Math.pow(k,i)).toFixed(d))+' '+s[i];}
// Upload SEQUENTIAL: satu file per request — stabil untuk 1, 2, atau banyak file
// dan tidak pernah menabrak batas post_max_size server karena ukuran request = 1 file saja.
function uploadFiles(){
    if(uploadInProgress){showToast('Upload masih berjalan','warn');return;}
    if(!selectedFiles.length){showToast('Belum ada file dipilih','error');return;}
    uploadInProgress=true;
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const files=[...selectedFiles];
    const prog=document.getElementById('uploadProgress'),btn=document.getElementById('uploadBtn');
    prog.style.display='block'; btn.disabled=true;
    const indiv=document.getElementById('individualProgress'); indiv.innerHTML='';
    files.forEach((f,i)=>{
        const el=document.createElement('div');
        el.innerHTML=`<div class="progress-meta"><span style="color:var(--text-dim);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%;" title="${f.name}">${f.name}</span><span id="fp_${i}" style="color:var(--gold);font-size:11px;">menunggu…</span></div><div class="progress-bar-wrap"><div id="fb_${i}" class="progress-bar-fill"></div></div>`;
        indiv.appendChild(el);
    });
    const totalBytes=files.reduce((s,f)=>s+f.size,0)||1;
    let doneBytes=0, uploaded=0, failed=0;
    const failMsgs=[];

    function setFileState(i,pct,text,color){
        const b=document.getElementById('fb_'+i),p=document.getElementById('fp_'+i);
        if(b){b.style.width=pct+'%';if(color)b.style.background=color;}
        if(p){p.textContent=text;if(color)p.style.color=color;}
    }
    function setOverall(extraLoaded){
        const p=Math.min(100,Math.round(((doneBytes+extraLoaded)/totalBytes)*100));
        document.getElementById('overallProgressBar').style.width=p+'%';
        document.getElementById('overallPercent').textContent=p+'%';
    }
    function uploadOne(i,attempt){
        return new Promise(resolve=>{
            const f=files[i];
            const fd=new FormData();
            fd.append('current_folder',folder);
            fd.append('csrf_token',CSRF_TOKEN);
            fd.append('files[]',f);
            const xhr=new XMLHttpRequest();
            xhr.timeout=10*60*1000; // 10 menit per file
            xhr.upload.addEventListener('progress',e=>{
                if(e.lengthComputable){
                    setFileState(i,Math.round((e.loaded/e.total)*100),Math.round((e.loaded/e.total)*100)+'%');
                    setOverall(Math.min(e.loaded,f.size));
                }
            });
            xhr.onload=()=>{
                let r=null;
                try{r=JSON.parse(xhr.responseText);}catch(e){}
                const res=r&&r.results&&r.results[0];
                if(xhr.status===200&&res&&res.success){
                    uploaded++;
                    setFileState(i,100,'✓ selesai','var(--success)');
                }else{
                    const msg=(res&&res.message)||(r&&r.message)||('HTTP '+xhr.status);
                    failed++; failMsgs.push(f.name+': '+msg);
                    setFileState(i,100,'✗ gagal','var(--danger)');
                }
                doneBytes+=f.size; setOverall(0); resolve();
            };
            const retryOrFail=()=>{
                if(attempt<2){ // retry otomatis 1x kalau koneksi putus
                    setFileState(i,0,'coba ulang…','var(--gold)');
                    setTimeout(()=>uploadOne(i,attempt+1).then(resolve),800);
                }else{
                    failed++; failMsgs.push(f.name+': koneksi gagal');
                    setFileState(i,100,'✗ gagal','var(--danger)');
                    doneBytes+=f.size; setOverall(0); resolve();
                }
            };
            xhr.onerror=retryOrFail;
            xhr.ontimeout=retryOrFail;
            xhr.open('POST','api/upload.php');
            xhr.send(fd);
        });
    }
    (async()=>{
        for(let i=0;i<files.length;i++){
            btn.innerHTML=`<i class="fas fa-spinner fa-spin"></i> Upload ${i+1}/${files.length}…`;
            await uploadOne(i,1);
        }
        btn.disabled=false; btn.innerHTML='<i class="fas fa-upload"></i> Upload All';
        uploadInProgress=false;
        if(failed){
            showToast(`${uploaded}/${files.length} berhasil — ${failMsgs[0]}`,'warn');
            setTimeout(()=>location.reload(),2600);
        }else{
            showToast(`Semua ${uploaded} file terupload!`,'success');
            setTimeout(()=>location.reload(),1400);
        }
    })();
}

// ══ SEARCH + FILTER + PAGINATION ══
let activeTypeFilter = '';
const TYPE_MAP = {
    image:   ['jpg','jpeg','png','gif','bmp','webp','svg','ico'],
    video:   ['mp4','avi','mkv','mov','wmv','webm'],
    audio:   ['mp3','wav','flac','m4a','ogg'],
    doc:     ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','rtf','csv','odt','md','log'],
    archive: ['zip','rar','7z','tar','gz'],
};
function filterByType(type){
    activeTypeFilter=type;
    document.querySelectorAll('.tfbtn').forEach(b=>b.classList.toggle('active',b.dataset.type===type));
    applyFilters();
}
function applyFilters(){
    const query=(document.getElementById('searchInput').value||'').trim().toLowerCase();
    document.getElementById('searchClear').style.display=query?'block':'none';
    filteredRows=allRows.filter(r=>{
        if(activeTypeFilter){
            if(activeTypeFilter==='folder'){if(r.dataset.isFolder!=='1')return false;}
            else{if(r.dataset.isFolder==='1')return false;const exts=TYPE_MAP[activeTypeFilter]||[];if(!exts.includes(r.dataset.type))return false;}
        }
        if(query){const name=(r.querySelector('.fname')?.textContent||'').toLowerCase();if(!name.includes(query))return false;}
        return true;
    });
    currentPage=1;
    const el=document.getElementById('typeFilterCount');
    if(el) el.textContent=filteredRows.length+' item'+(filteredRows.length!==1?'s':'');
    updateSearchCount(query);
    const noRes=document.getElementById('noResults');
    if(!filteredRows.length&&(query||activeTypeFilter)){document.getElementById('noResultsQuery').textContent=query||activeTypeFilter;noRes.style.display='block';}
    else noRes.style.display='none';
    renderPage();
}
function handleSearch(q){applyFilters();}
function clearSearch(){document.getElementById('searchInput').value='';applyFilters();}
function updateSearchCount(q){const el=document.getElementById('searchCount');if(!el)return;el.textContent=q&&q.trim()?filteredRows.length+' result'+(filteredRows.length!==1?'s':''):allRows.length+' item'+(allRows.length!==1?'s':'');}
function changePerPage(val){
    ITEMS_PER_PAGE=parseInt(val)||0;
    currentPage=1;
    localStorage.setItem('storagePerPage',val);
    renderPage();
}
function renderPage(){
    const ipp=ITEMS_PER_PAGE||filteredRows.length;
    const start=(currentPage-1)*ipp,end=start+ipp;
    allRows.forEach(r=>r.style.display='none');
    filteredRows.slice(start,end).forEach(r=>r.style.display='');
    buildPagination();
}
function buildPagination(){
    const ipp=ITEMS_PER_PAGE||filteredRows.length||1;
    const total=Math.ceil(filteredRows.length/ipp),pag=document.getElementById('pagination');
    if(!pag)return;
    if(total<=1){pag.style.display='none';return;}
    pag.style.display='flex';
    const s=(currentPage-1)*ipp+1,e=Math.min(currentPage*ipp,filteredRows.length);
    let html=`<button class="pag-btn ${currentPage===1?'disabled':''}" onclick="goPage(${currentPage-1})"><i class="fas fa-chevron-left"></i></button>`;
    for(let i=1;i<=total;i++){
        if(total<=7||i===1||i===total||(i>=currentPage-1&&i<=currentPage+1))html+=`<button class="pag-btn ${i===currentPage?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if(i===currentPage-2||i===currentPage+2)html+=`<span class="pag-dots">…</span>`;
    }
    html+=`<button class="pag-btn ${currentPage===total?'disabled':''}" onclick="goPage(${currentPage+1})"><i class="fas fa-chevron-right"></i></button>`;
    html+=`<span class="pag-info">${s}–${e} of ${filteredRows.length}</span>`;
    pag.innerHTML=html;
}
function goPage(p){const ipp=ITEMS_PER_PAGE||filteredRows.length||1;const t=Math.ceil(filteredRows.length/ipp);if(p<1||p>t)return;currentPage=p;renderPage();document.querySelector('.file-table-wrap').scrollIntoView({behavior:'smooth',block:'start'});}

// ══ SORT ══
function _cmpVal(r,col){
    if(col==='name') return r.dataset.name.toLowerCase();
    if(col==='type') return r.dataset.type.toLowerCase();
    if(col==='size') return parseFloat(r.dataset.size);
    if(col==='date') return parseInt(r.dataset.date);
    return '';
}
function _sortArr(arr,col,dir){
    // Folders always on top (unless sorting by type explicitly)
    arr.sort((a,b)=>{
        if(col!=='type'){
            const af=a.dataset.isFolder==='1', bf=b.dataset.isFolder==='1';
            if(af&&!bf)return -1; if(!af&&bf)return 1;
        }
        const av=_cmpVal(a,col), bv=_cmpVal(b,col);
        if(av<bv)return dir==='asc'?-1:1;
        if(av>bv)return dir==='asc'?1:-1;
        return 0;
    });
}
function sortBy(col){
    document.querySelectorAll('th.sortable').forEach(th=>th.classList.remove('sort-asc','sort-desc'));
    const thIdx={'name':0,'type':1,'size':2,'date':3}[col];
    const th=document.querySelectorAll('th.sortable')[thIdx];
    if(sortCol===col) sortDir=sortDir==='asc'?'desc':'asc';
    else{sortCol=col;sortDir='asc';}
    th.classList.add('sort-'+sortDir);
    _sortArr(filteredRows,col,sortDir);
    _sortArr(allRows,col,sortDir);
    const tbody=document.querySelector('#fileTableWrap tbody');
    if(tbody) allRows.forEach(r=>tbody.appendChild(r));
    currentPage=1; renderPage();
}

// ══ BULK SELECT ══
function toggleSelectAll(chk){
    const visibleRows=filteredRows.slice((currentPage-1)*ITEMS_PER_PAGE,currentPage*ITEMS_PER_PAGE);
    visibleRows.forEach(r=>{
        const c=r.querySelector('.row-chk');
        if(c){c.checked=chk.checked;if(chk.checked)selectedItems.add(c.dataset.name);else selectedItems.delete(c.dataset.name);r.classList.toggle('selected-row',chk.checked);}
    });
    updateBulkToolbar();
}
let lastCheckedIdx = -1;
function toggleSelect(chk, event){
    const tr = chk.closest('tr');
    const currentIdx = allRows.indexOf(tr);
    if(event && event.shiftKey && lastCheckedIdx >= 0 && lastCheckedIdx !== currentIdx){
        const lo=Math.min(lastCheckedIdx,currentIdx), hi=Math.max(lastCheckedIdx,currentIdx);
        allRows.slice(lo, hi+1).forEach(r=>{
            if(r.style.display==='none') return;
            const c=r.querySelector('.row-chk');
            if(c){c.checked=true; selectedItems.add(c.dataset.name); r.classList.add('selected-row');}
        });
    } else {
        if(chk.checked) selectedItems.add(chk.dataset.name);
        else selectedItems.delete(chk.dataset.name);
        tr.classList.toggle('selected-row', chk.checked);
        lastCheckedIdx = currentIdx;
    }
    updateBulkToolbar();
}
function updateBulkToolbar(){
    const bar=document.getElementById('bulkToolbar');
    const count=selectedItems.size;
    const batchBtn=document.getElementById('batchRenameBtn');
    if(count>0){
        bar.classList.add('visible');
        document.getElementById('bulkCount').textContent=count+' selected';
        if(batchBtn) batchBtn.style.display='';
    } else {
        bar.classList.remove('visible');
        if(batchBtn) batchBtn.style.display='none';
    }
}
function clearSelection(){
    selectedItems.clear();
    document.querySelectorAll('.row-chk').forEach(c=>{c.checked=false;c.closest('tr').classList.remove('selected-row');});
    const sa=document.getElementById('selectAll');if(sa)sa.checked=false;
    updateBulkToolbar();
}
function bulkTrash(){
    if(!selectedItems.size){showToast('Nothing selected','warn');return;}
    customConfirm(`Move ${selectedItems.size} item(s) to trash?`,()=>{
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const names=Array.from(selectedItems);
    let done=0, failed=0;
    const total=names.length;
    function next(i){
        if(i>=total){
            const msg=`Moved ${done} item(s) to trash`+(failed?`, ${failed} failed`:'');
            showToast(msg,failed?'warn':'success');
            setTimeout(()=>location.reload(),1200);
            return;
        }
        const xhr=new XMLHttpRequest();
        xhr.open('POST','api/trash.php',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success)done++;else failed++;next(i+1);};
        xhr.onerror=()=>{failed++;next(i+1);};
        xhr.send('action=move_to_trash&item_name='+encodeURIComponent(names[i])+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    }
    next(0);
    },'Move to Trash');
}
function bulkDelete(){bulkTrash();}
function showBulkMoveModal(){
    if(!selectedItems.size){showToast('Nothing selected','warn');return;}
    moveTargetItem=null; moveIsBulk=true;
    document.getElementById('moveModalTitle').textContent='Move '+selectedItems.size+' Item(s)';
    document.getElementById('moveItemName').textContent=Array.from(selectedItems).join(', ');
    document.getElementById('moveMessage').style.display='none';
    populateMoveDropdown();
    openModal('moveModal');
}

// ══ MOVE SINGLE ══
function showMoveModal(name,isFolder){
    moveTargetItem={name,isFolder}; moveIsBulk=false;
    document.getElementById('moveModalTitle').textContent='Move '+(isFolder?'Folder':'File');
    document.getElementById('moveItemName').textContent=name;
    document.getElementById('moveMessage').style.display='none';
    populateMoveDropdown();
    openModal('moveModal');
}
function populateMoveDropdown(){
    const sel=document.getElementById('moveDestination');
    sel.innerHTML='';
    ALL_FOLDERS.forEach(f=>{
        const opt=document.createElement('option');
        opt.value=f.path; opt.textContent=f.label;
        sel.appendChild(opt);
    });
}
function performMove(){
    const dest=document.getElementById('moveDestination').value;
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST',moveIsBulk?'api/bulk.php':'api/move.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        const r=JSON.parse(xhr.responseText);
        if(r.success){showMoveMsg(r.message,'success');setTimeout(()=>{closeModal('moveModal');location.reload();},1000);}
        else showMoveMsg(r.message,'error');
    };
    if(moveIsBulk){
        const items=Array.from(selectedItems).map(n=>({name:n}));
        xhr.send('action=move_bulk&current_folder='+encodeURIComponent(folder)+'&to_folder='+encodeURIComponent(dest)+'&items='+encodeURIComponent(JSON.stringify(items))+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    } else {
        xhr.send('item_name='+encodeURIComponent(moveTargetItem.name)+'&from_folder='+encodeURIComponent(folder)+'&to_folder='+encodeURIComponent(dest)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    }
}
function showMoveMsg(msg,type){
    const el=document.getElementById('moveMessage');
    el.className='msg-box '+(type==='success'?'msg-success':'msg-error');
    el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;
}

// ══ COPY LINK ══
function copyFileLink(filename,folderOverride){
    const folder=(folderOverride!==undefined&&folderOverride!==null)?folderOverride:(new URLSearchParams(window.location.search).get('folder')||'');
    const url=BASE_URL+'/uploads/'+(folder?folder+'/':'')+filename;
    if(navigator.clipboard)navigator.clipboard.writeText(url).then(()=>showToast('Link copied!','success'),()=>fallbackCopy(url));
    else fallbackCopy(url);
}
function fallbackCopy(url){const el=document.createElement('input');el.style.position='absolute';el.style.left='-9999px';el.value=url;document.body.appendChild(el);el.select();document.execCommand('copy');document.body.removeChild(el);showToast('Link copied!','success');}

// ══ PREVIEW ══
// folderOverride: dipakai Recently Added — file bisa berada di folder lain
// dari yang sedang dibuka, jadi path-nya gak boleh ngikut ?folder= di URL.
function viewFile(filename,ext,fromLightbox=false,folderOverride=null){
    const folder=(folderOverride!==null)?folderOverride:(new URLSearchParams(window.location.search).get('folder')||'');
    const path='uploads/'+(folder?folder+'/':'')+filename;
    document.getElementById('fileName').textContent=filename;
    document.getElementById('previewBody').innerHTML='<div style="padding:50px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    openModal('previewModal');
    currentPreviewFile=path; currentRotation=0;
    ext=ext.toLowerCase();
    const isImg=['jpg','jpeg','png','gif','bmp','webp','svg'].includes(ext);
    // Lightbox state
    if(isImg && !fromLightbox){
        lbIndex=imageRows.findIndex(r=>r.dataset.name===filename);
    }
    const lbPrev=document.getElementById('lbPrev'),lbNext=document.getElementById('lbNext'),lbCtr=document.getElementById('lbCounter');
    if(isImg && imageRows.length>1 && lbIndex>=0){
        lbPrev.style.display=''; lbNext.style.display=''; lbCtr.style.display='';
        lbCtr.textContent=(lbIndex+1)+' / '+imageRows.length;
        lbPrev.disabled=(lbIndex<=0); lbNext.disabled=(lbIndex>=imageRows.length-1);
    } else {
        lbPrev.style.display='none'; lbNext.style.display='none'; lbCtr.style.display='none';
    }
    const body=document.getElementById('previewBody');
    if(isImg){
        body.innerHTML=`<img id="lbImg" src="${path}" style="max-width:100%;max-height:58vh;border-radius:10px;border:1px solid var(--glass-border);" onerror="this.alt='Failed to load'">
            <div id="imgInfo" style="font-size:11px;color:var(--text-muted);margin-top:8px;text-align:center;"></div>
            <div style="display:flex;justify-content:center;gap:8px;margin-top:10px;flex-wrap:wrap;">
                <button onclick="toggleFullscreen()" class="btn btn-ghost btn-sm"><i class="fas fa-expand"></i> Fullscreen</button>
                <button onclick="zoomImage()" class="btn btn-ghost btn-sm"><i class="fas fa-search-plus"></i> Zoom</button>
                <button onclick="rotateImage()" class="btn btn-ghost btn-sm"><i class="fas fa-redo"></i> Rotate</button>
                <button onclick="copyFileLink('${filename}','${folder}')" class="btn btn-gold btn-sm"><i class="fas fa-link"></i> Copy Link</button>
                <button onclick="copyFilePath('${filename}','${folder}')" class="btn btn-ghost btn-sm"><i class="fas fa-code"></i> Copy Path</button>
            </div>`;
        const img=document.getElementById('lbImg');
        img.onload=()=>{const info=document.getElementById('imgInfo');if(info)info.textContent=img.naturalWidth+'×'+img.naturalHeight+'px';};
    } else if(['mp3','wav','ogg','m4a','flac'].includes(ext)){
        body.innerHTML=`<div style="padding:20px;"><audio controls style="width:100%;"><source src="${path}">Not supported.</audio></div>`;
    } else if(['mp4','webm','mov'].includes(ext)){
        body.innerHTML=`<video controls style="max-width:100%;max-height:58vh;border-radius:8px;background:#000;"><source src="${path}">Not supported.</video>`;
    } else if(['txt','md','log','json','xml','csv','html','css','js'].includes(ext)){
        fetch(path+'?nocache='+Date.now()).then(r=>r.text()).then(t=>{
            const truncated=t.length>10000;
            const display=truncated?t.substring(0,10000)+'\n\n… [truncated]':t;
            body.innerHTML=`<div style="text-align:left;background:rgba(0,0,0,.3);border:1px solid var(--glass-border);border-radius:10px;padding:18px;max-height:52vh;overflow:auto;margin-bottom:10px;"><pre style="color:var(--text);font-family:'Courier New',monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;">${escapeHtml(display)}</pre></div>
            <div style="display:flex;justify-content:center;gap:8px;">
                <button onclick="closeModal('previewModal');openFileEditor('${filename}','${ext}')" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit File</button>
                <button onclick="copyFileLink('${filename}','${folder}')" class="btn btn-gold btn-sm"><i class="fas fa-link"></i> Copy Link</button>
            </div>`;
        }).catch(()=>{body.innerHTML=`<div style="text-align:center;padding:30px;color:var(--text-muted);">Cannot load preview. <button onclick="downloadCurrentFile()" class="btn btn-primary" style="margin-top:12px;"><i class="fas fa-download"></i> Download</button></div>`;});
    } else if(ext === 'pdf'){
        body.innerHTML=`<iframe src="${path}" style="width:100%;height:62vh;border-radius:8px;border:1px solid var(--glass-border);background:#fff;"></iframe>
            <div style="display:flex;justify-content:center;gap:8px;margin-top:10px;">
                <a href="${path}" target="_blank" class="btn btn-ghost btn-sm"><i class="fas fa-external-link-alt"></i> Open in New Tab</a>
                <button onclick="downloadCurrentFile()" class="btn btn-ghost btn-sm"><i class="fas fa-download"></i> Download</button>
                <button onclick="copyFileLink('${filename}','${folder}')" class="btn btn-gold btn-sm"><i class="fas fa-link"></i> Copy Link</button>
            </div>`;
    } else {
        body.innerHTML=`<div style="text-align:center;padding:40px;color:var(--text-muted);"><div style="font-size:50px;margin-bottom:14px;">📄</div><h3 style="color:var(--text-dim);margin-bottom:8px;">No preview</h3><p style="font-size:13px;">.${ext} cannot be previewed</p><div style="display:flex;gap:8px;justify-content:center;margin-top:18px;"><button onclick="downloadCurrentFile()" class="btn btn-primary"><i class="fas fa-download"></i> Download</button><button onclick="copyFileLink('${filename}','${folder}')" class="btn btn-gold"><i class="fas fa-link"></i> Copy Link</button></div></div>`;
    }
}
function downloadCurrentFile(){if(!currentPreviewFile)return;const a=document.createElement('a');a.href=currentPreviewFile;a.download=currentPreviewFile.split('/').pop();document.body.appendChild(a);a.click();document.body.removeChild(a);showToast('Download started!','success');}
function zoomImage(){const img=document.querySelector('#previewBody img');if(!img)return;const z=img.style.maxHeight==='none';img.style.maxWidth=z?'100%':'none';img.style.maxHeight=z?'58vh':'none';}
function rotateImage(){const img=document.querySelector('#previewBody img');if(!img)return;currentRotation=(currentRotation+90)%360;img.style.transform=`rotate(${currentRotation}deg)`;img.style.transition='transform .3s';}

// ══ RENAME ══
function showEditModal(name,isFolder){
    currentEditItem={name,isFolder};
    document.getElementById('editModalTitle').textContent=isFolder?'Rename Folder':'Rename File';
    document.getElementById('currentName').value=name;
    document.getElementById('newName').value='';
    document.getElementById('editMessage').style.display='none';
    openModal('editModal');
    setTimeout(()=>document.getElementById('newName').focus(),120);
}
function performRename(){
    const newName=document.getElementById('newName').value.trim();
    if(!newName){showEditMsg('Name cannot be empty','error');return;}
    if(newName===currentEditItem.name){showEditMsg('Same as current name','error');return;}
    if(!/^[a-zA-Z0-9_.\-]+$/.test(newName)){showEditMsg('Use letters, numbers, dot, _ or -','error');return;}
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/actions.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showEditMsg('Renamed! Refreshing...','success');setTimeout(()=>location.reload(),900);}else showEditMsg(r.message,'error');};
    xhr.send('action=rename&old_name='+encodeURIComponent(currentEditItem.name)+'&new_name='+encodeURIComponent(newName)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showEditMsg(msg,type){const el=document.getElementById('editMessage');el.className='msg-box '+(type==='success'?'msg-success':'msg-error');el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;}

// ══ DELETE ══
function showDeleteModal(name,isFolder){
    currentDeleteItem={name,isFolder};
    document.getElementById('deleteModalTitle').textContent=isFolder?'Delete Folder':'Delete File';
    document.getElementById('deleteItemName').textContent=name;
    openModal('deleteModal');
}
function performDelete(){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const action=currentDeleteItem.isFolder?'delete_folder':'delete_file';
    const key=currentDeleteItem.isFolder?'folder_name':'file_name';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/actions.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);closeModal('deleteModal');if(r.success){showToast('Deleted!','success');setTimeout(()=>location.reload(),900);}else showToast(r.message,'error');};
    xhr.send('action='+action+'&'+key+'='+encodeURIComponent(currentDeleteItem.name)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ CHANGE PASSWORD ══
function showChangePwModal(){document.getElementById('currentPw').value='';document.getElementById('newPw').value='';document.getElementById('confirmPw').value='';document.getElementById('pwMessage').style.display='none';openModal('changePwModal');}
function performChangePw(){
    const cur=document.getElementById('currentPw').value;
    const nw=document.getElementById('newPw').value;
    const conf=document.getElementById('confirmPw').value;
    if(!cur||!nw||!conf){showPwMsg('All fields required','error');return;}
    const btn=document.getElementById('changePwBtn');
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Updating...';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/change_password.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Update Password';
        const r=JSON.parse(xhr.responseText);
        if(r.success){showPwMsg(r.message,'success');setTimeout(()=>closeModal('changePwModal'),2000);}
        else showPwMsg(r.message,'error');
    };
    xhr.send('current_password='+encodeURIComponent(cur)+'&new_password='+encodeURIComponent(nw)+'&confirm_password='+encodeURIComponent(conf)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showPwMsg(msg,type){const el=document.getElementById('pwMessage');el.className='msg-box '+(type==='success'?'msg-success':'msg-error');el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;}

// ══ ACTIVITY LOG ══
function showLogModal(){
    openModal('logModal');
    document.getElementById('logContent').innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i></div>';
    fetch('api/log.php?csrf_token='+encodeURIComponent(CSRF_TOKEN))
        .then(r=>r.json()).then(r=>{
            if(!r.entries||!r.entries.length){document.getElementById('logContent').innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);">No activity logged yet.</div>';return;}
            document.getElementById('logContent').innerHTML=r.entries.map(e=>`<div class="log-entry">${e}</div>`).join('');
        }).catch(()=>{document.getElementById('logContent').innerHTML='<div style="padding:20px;color:#e07070;">Failed to load log.</div>';});
}

// ══ VIEW TOGGLE ══
function toggleTheme(){
    const isLight = document.body.classList.toggle('light');
    const ic = document.querySelector('#btnTheme i');
    if(ic){ ic.className = isLight ? 'fas fa-moon' : 'fas fa-sun'; }
    localStorage.setItem('storageTheme', isLight ? 'light' : 'dark');
}
function setView(mode){
    const wrap=document.getElementById('fileTableWrap');
    document.getElementById('btnList').classList.toggle('active',mode==='list');
    document.getElementById('btnGrid').classList.toggle('active',mode==='grid');
    if(mode==='grid')wrap.classList.add('grid-mode'); else wrap.classList.remove('grid-mode');
    localStorage.setItem('storageView',mode);
    if(allRows.length)renderPage();
}

// ══ TOAST ══
function showToast(msg,type='success'){
    const toast=document.getElementById('toast'),icon=document.getElementById('toastIcon');
    document.getElementById('toastMessage').textContent=msg;
    const c={success:'var(--success)',error:'var(--danger)',warn:'var(--gold)',info:'var(--info)'};
    const ic={success:'check-circle',error:'exclamation-circle',warn:'exclamation-triangle',info:'info-circle'};
    icon.className='fas fa-'+(ic[type]||'check-circle'); icon.style.color=c[type]||c.success;
    toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'),3200);
}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

// ══ LIGHTBOX NAVIGATION ══
function lightboxNav(dir){
    lbIndex = Math.max(0, Math.min(imageRows.length-1, lbIndex+dir));
    const row = imageRows[lbIndex];
    if(row) viewFile(row.dataset.name, row.dataset.type, true);
}

// ══ CONTEXT MENU ══
let ctxRow = null;
function initContextMenu(){
    const menu = document.getElementById('ctxMenu');
    document.querySelector('#fileTableWrap tbody')?.addEventListener('contextmenu', e=>{
        const tr = e.target.closest('tr');
        if(!tr) return;
        e.preventDefault();
        ctxRow = tr;
        const isFolder = tr.dataset.isFolder === '1';
        const name = tr.dataset.name;
        document.getElementById('ctxLabel').textContent = name.length>22 ? name.substring(0,22)+'…' : name;
        document.getElementById('ctxOpen').style.display    = isFolder ? '' : 'none';
        document.getElementById('ctxPreview').style.display = isFolder ? 'none' : '';
        document.getElementById('ctxCopyLink').style.display = isFolder ? 'none' : '';
        document.getElementById('ctxDownload').style.display = isFolder ? 'none' : '';
        document.getElementById('ctxDuplicate').style.display = isFolder ? 'none' : '';
        // Position
        let x=e.clientX, y=e.clientY;
        menu.style.left=x+'px'; menu.style.top=y+'px';
        menu.classList.add('open');
        // Adjust if out of viewport
        requestAnimationFrame(()=>{
            const r=menu.getBoundingClientRect();
            if(r.right>window.innerWidth) menu.style.left=(x-r.width)+'px';
            if(r.bottom>window.innerHeight) menu.style.top=(y-r.height)+'px';
        });
    });
    document.addEventListener('click', ()=>menu.classList.remove('open'));
    document.addEventListener('scroll', ()=>menu.classList.remove('open'), true);
}
function ctxAction(action){
    if(!ctxRow) return;
    document.getElementById('ctxMenu').classList.remove('open');
    const name = ctxRow.dataset.name;
    const isFolder = ctxRow.dataset.isFolder === '1';
    const folder = new URLSearchParams(window.location.search).get('folder')||'';
    const ext = ctxRow.dataset.type;
    const path = 'uploads/'+(folder?folder+'/':'')+name;
    switch(action){
        case 'open':    location.href='dashboard.php?folder='+(folder?folder+'/':'')+encodeURIComponent(name); break;
        case 'preview': viewFile(name, ext); break;
        case 'copylink': copyFileLink(name); break;
        case 'download': {const a=document.createElement('a');a.href=path;a.download=name;document.body.appendChild(a);a.click();document.body.removeChild(a);} break;
        case 'rename':  showEditModal(name, isFolder); break;
        case 'move':    showMoveModal(name, isFolder); break;
        case 'duplicate': duplicateFile(name); break;
        case 'delete':  showDeleteModal(name, isFolder); break;
    }
}

// ══ KEYBOARD SHORTCUTS ══
function initKeyboard(){
    document.addEventListener('keydown', e=>{
        if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.isContentEditable) return;
        if(document.querySelector('.modal-overlay.open')) return;
        if(e.key==='Delete'&&selectedItems.size){e.preventDefault();bulkDelete();}
        else if(e.key==='Delete'&&ctxRow){e.preventDefault();showDeleteModal(ctxRow.dataset.name,ctxRow.dataset.isFolder==='1');}
        else if((e.ctrlKey||e.metaKey)&&e.key==='a'){e.preventDefault();const sa=document.getElementById('selectAll');if(sa){sa.checked=true;toggleSelectAll(sa);}}
    });
}

// ══ DRAG & DROP TO FOLDER (move) ══
function initDragDrop(){
    const tbody = document.querySelector('#fileTableWrap tbody');
    if(!tbody) return;
    tbody.addEventListener('dragstart', e=>{
        const tr = e.target.closest('tr'); if(!tr) return;
        tr.classList.add('dragging');
        e.dataTransfer.setData('text/plain', tr.dataset.name);
        e.dataTransfer.setData('application/isFolder', tr.dataset.isFolder);
        e.dataTransfer.effectAllowed = 'move';
    });
    tbody.addEventListener('dragend', e=>{
        document.querySelectorAll('tr.dragging').forEach(r=>r.classList.remove('dragging'));
        document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
    });
    tbody.addEventListener('dragover', e=>{
        e.preventDefault(); e.dataTransfer.dropEffect='move';
        const tr=e.target.closest('tr');
        document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
        if(tr&&tr.dataset.isFolder==='1'&&!tr.classList.contains('dragging')) tr.classList.add('drag-over');
    });
    tbody.addEventListener('dragleave', e=>{
        if(!e.relatedTarget||!e.relatedTarget.closest('tr.drag-over')) document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
    });
    tbody.addEventListener('drop', e=>{
        e.preventDefault();
        document.querySelectorAll('tr.drag-over').forEach(r=>r.classList.remove('drag-over'));
        const destRow=e.target.closest('tr');
        if(!destRow||destRow.dataset.isFolder!=='1') return;
        const srcName=e.dataTransfer.getData('text/plain');
        if(srcName===destRow.dataset.name) return;
        const folder=new URLSearchParams(window.location.search).get('folder')||'';
        const destFolder=(folder?folder+'/':'')+destRow.dataset.name;
        const xhr=new XMLHttpRequest();
        xhr.open('POST','api/move.php',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast(srcName+' moved!','success');setTimeout(()=>location.reload(),900);}else showToast(r.message,'error');};
        xhr.send('item_name='+encodeURIComponent(srcName)+'&from_folder='+encodeURIComponent(folder)+'&to_folder='+encodeURIComponent(destFolder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    });
}

// ══ PASTE TO UPLOAD ══
function initPasteUpload(){
    document.addEventListener('paste', e=>{
        if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;
        const items = Array.from(e.clipboardData?.items||[]);
        const imgItems = items.filter(it=>it.kind==='file'&&it.type.startsWith('image/'));
        if(!imgItems.length) return;
        const files = imgItems.map(it=>it.getAsFile());
        handleFiles(files);
        showToast(files.length+' image(s) pasted — click Upload All','info');
        // Scroll to upload area
        document.getElementById('dropArea')?.scrollIntoView({behavior:'smooth',block:'center'});
    });
}

// ══ SESSION EXTEND ══
function extendSession(){
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/session.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');};
    xhr.send('csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ DUPLICATE FILE ══
function duplicateFile(filename){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/actions.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast(r.message,'success');setTimeout(()=>location.reload(),1000);}else showToast(r.message,'error');};
    xhr.send('action=copy_file&file_name='+encodeURIComponent(filename)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ BULK DOWNLOAD ZIP ══
function bulkDownloadZip(){
    if(!selectedItems.size){showToast('Nothing selected','warn');return;}
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const items=JSON.stringify(Array.from(selectedItems).map(n=>({name:n})));
    document.getElementById('bulkZipCsrf').value   = CSRF_TOKEN;
    document.getElementById('bulkZipFolder').value = folder;
    document.getElementById('bulkZipItems').value  = items;
    showToast('Preparing ZIP download…','info');
    document.getElementById('bulkZipForm').submit();
}

// ══ MOVE TO TRASH (single) ══
function moveToTrash(name){
    customConfirm('Move "'+name+'" to trash?',()=>{
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success)setTimeout(()=>location.reload(),900);};
    xhr.send('action=move_to_trash&item_name='+encodeURIComponent(name)+'&current_folder='+encodeURIComponent(folder)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    },'Move to Trash');
}

// ══ TRASH MODAL ══
function showTrashModal(){
    openModal('trashModal');
    const el=document.getElementById('trashContent');
    el.innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    fetch('api/trash.php?action=list&csrf_token='+encodeURIComponent(CSRF_TOKEN))
        .then(r=>r.json()).then(r=>{
            if(!r.items||!r.items.length){el.innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-trash-alt" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>Trash is empty</div>';return;}
            el.innerHTML=`<table style="width:100%;border-collapse:collapse;">
                <thead><tr>
                    <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--text-muted);text-transform:uppercase;border-bottom:1px solid var(--glass-border);">Name</th>
                    <th style="padding:9px 14px;font-size:11px;color:var(--text-muted);text-transform:uppercase;border-bottom:1px solid var(--glass-border);">From</th>
                    <th style="padding:9px 14px;font-size:11px;color:var(--text-muted);text-transform:uppercase;border-bottom:1px solid var(--glass-border);">Deleted</th>
                    <th style="padding:9px 14px;border-bottom:1px solid var(--glass-border);"></th>
                </tr></thead>
                <tbody>${r.items.map(item=>`<tr style="border-bottom:1px solid rgba(255,255,255,.04);">
                    <td style="padding:9px 14px;font-size:13px;color:var(--text);">${item.is_dir?'📁 ':'📄 '}${escapeHtml(item.original_name)}</td>
                    <td style="padding:9px 14px;font-size:11px;color:var(--text-muted);">${escapeHtml(item.original_folder||'root')}</td>
                    <td style="padding:9px 14px;font-size:11px;color:var(--text-muted);white-space:nowrap;">${item.deleted_at_fmt}</td>
                    <td style="padding:9px 14px;">
                        <div style="display:flex;gap:5px;">
                            <button onclick="restoreTrashItem('${escapeHtml(item.trash_name)}')" class="btn btn-success btn-sm" title="Restore"><i class="fas fa-undo"></i></button>
                            <button onclick="deleteTrashItem('${escapeHtml(item.trash_name)}')" class="btn btn-danger btn-sm" title="Delete Permanently"><i class="fas fa-times"></i></button>
                        </div>
                    </td>
                </tr>`).join('')}</tbody>
            </table>`;
        }).catch(()=>{el.innerHTML='<div style="padding:20px;color:#e07070;">Failed to load trash.</div>';});
}
function restoreTrashItem(trashName){
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success){closeModal('trashModal');setTimeout(()=>location.reload(),900);}};
    xhr.send('action=restore&trash_name='+encodeURIComponent(trashName)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function deleteTrashItem(trashName){
    customConfirm('Permanently delete this item?',()=>{
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success)showTrashModal();};
    xhr.send('action=delete_permanent&trash_name='+encodeURIComponent(trashName)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    },'Delete Permanently');
}
function emptyTrash(){
    customConfirm('Permanently delete ALL items in trash?',()=>{
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/trash.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);showToast(r.message,r.success?'success':'error');if(r.success)showTrashModal();};
    xhr.send('action=empty_trash&csrf_token='+encodeURIComponent(CSRF_TOKEN));
    },'Empty Trash');
}

// ══ BATCH RENAME ══
function showBatchRenameModal(){
    if(!selectedItems.size){showToast('Select items first','warn');return;}
    document.getElementById('batchCount').textContent=selectedItems.size;
    document.getElementById('batchPattern').value='';
    document.getElementById('batchStart').value='1';
    document.getElementById('batchPadding').value='3';
    document.getElementById('batchRenameMsg').style.display='none';
    updateBatchPreview();
    openModal('batchRenameModal');
    setTimeout(()=>document.getElementById('batchPattern').focus(),120);
}
function updateBatchPreview(){
    const pattern=document.getElementById('batchPattern').value||'name';
    const start=parseInt(document.getElementById('batchStart').value)||1;
    const pad=parseInt(document.getElementById('batchPadding').value)||3;
    document.getElementById('batchPreview').textContent=pattern.replace(/ /g,'_')+'_'+String(start).padStart(pad,'0')+'.ext';
}
function performBatchRename(){
    const pattern=document.getElementById('batchPattern').value.trim();
    if(!pattern){showBatchMsg('Pattern cannot be empty','error');return;}
    if(!/^[a-zA-Z0-9_\- ]+$/.test(pattern)){showBatchMsg('Only letters, numbers, spaces, _ or - allowed','error');return;}
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const items=JSON.stringify(Array.from(selectedItems).map(n=>({name:n})));
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/batch_rename.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        const r=JSON.parse(xhr.responseText);
        if(r.success){showBatchMsg(`Renamed ${r.renamed} items!`,'success');setTimeout(()=>{closeModal('batchRenameModal');location.reload();},1200);}
        else showBatchMsg(r.message,'error');
    };
    xhr.send('pattern='+encodeURIComponent(pattern)+'&start_num='+document.getElementById('batchStart').value+'&padding='+document.getElementById('batchPadding').value+'&current_folder='+encodeURIComponent(folder)+'&items='+encodeURIComponent(items)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function showBatchMsg(msg,type){const el=document.getElementById('batchRenameMsg');el.className='msg-box '+(type==='success'?'msg-success':'msg-error');el.innerHTML='<i class="fas fa-'+(type==='success'?'check-circle':'exclamation-circle')+'"></i> '+msg;}

// ══ SHARE LINK ══
let currentShareFile = '';
function showShareModal(filename){
    currentShareFile=filename;
    document.getElementById('shareFileName').textContent=filename;
    document.getElementById('shareMsg').style.display='none';
    document.getElementById('shareResult').style.display='none';
    document.getElementById('shareQrWrap').innerHTML='';
    openModal('shareModal');
}
function createShareLink(){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const hours=document.getElementById('shareExpiry').value;
    const btn=document.getElementById('createShareBtn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Generating…';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/share.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        btn.disabled=false; btn.innerHTML='<i class="fas fa-link"></i> Generate Link';
        const r=JSON.parse(xhr.responseText);
        if(r.success){
            document.getElementById('shareUrl').value=r.url;
            document.getElementById('shareExpiresFmt').textContent=r.expires_fmt;
            document.getElementById('shareResult').style.display='block';
            // Generate QR
            const qrWrap=document.getElementById('shareQrWrap');
            qrWrap.innerHTML='';
            if(typeof QRCode!=='undefined'){
                new QRCode(qrWrap,{text:r.url,width:160,height:160,colorDark:'#052cf0',colorLight:'#ffffff'});
            }
        } else {
            const el=document.getElementById('shareMsg');
            el.className='msg-box msg-error'; el.innerHTML='<i class="fas fa-exclamation-circle"></i> '+r.message;
        }
    };
    xhr.send('action=create&file_name='+encodeURIComponent(currentShareFile)+'&current_folder='+encodeURIComponent(folder)+'&expires_hours='+encodeURIComponent(hours)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function copyShareUrl(){
    const url=document.getElementById('shareUrl').value;
    if(navigator.clipboard)navigator.clipboard.writeText(url).then(()=>showToast('Share URL copied!','success'));
    else{const el=document.getElementById('shareUrl');el.select();document.execCommand('copy');showToast('Share URL copied!','success');}
}

// ══ EDIT TEXT FILE ══
let editingFileName='';
function openFileEditor(filename,ext){
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const path='uploads/'+(folder?folder+'/':'')+filename;
    editingFileName=filename;
    document.getElementById('editFileName').textContent=filename;
    document.getElementById('editFileMsg').style.display='none';
    document.getElementById('editFileContent').value='Loading…';
    openModal('editFileModal');
    fetch(path+'?nocache='+Date.now()).then(r=>r.text()).then(t=>{
        document.getElementById('editFileContent').value=t;
    }).catch(()=>{document.getElementById('editFileContent').value='';showToast('Failed to load file','error');});
}
function saveFileEdit(){
    const content=document.getElementById('editFileContent').value;
    const folder=new URLSearchParams(window.location.search).get('folder')||'';
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/edit_file.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{
        const r=JSON.parse(xhr.responseText);
        const el=document.getElementById('editFileMsg');
        el.className='msg-box '+(r.success?'msg-success':'msg-error');
        el.innerHTML='<i class="fas fa-'+(r.success?'check-circle':'exclamation-circle')+'"></i> '+r.message;
        if(r.success) showToast('File saved!','success');
    };
    xhr.send('file_name='+encodeURIComponent(editingFileName)+'&current_folder='+encodeURIComponent(folder)+'&content='+encodeURIComponent(content)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ FOLDER COLOR/ICON ══
let fcFolderName='', fcFolderKey='';
function showFolderColorModal(name,key,curColor,curIcon){
    fcFolderName=name; fcFolderKey=key;
    document.getElementById('fcFolderKey').value=key;
    document.getElementById('fcIconInput').value=curIcon||'';
    document.getElementById('fcColorInput').value=curColor||'#FFD700';
    document.getElementById('fcPreviewName').textContent=name;
    document.getElementById('fcPreviewIcon').textContent=curIcon||'📁';
    document.getElementById('fcPreviewIcon').style.color=curColor||'';
    openModal('folderColorModal');
}
function selectIcon(em){
    document.getElementById('fcIconInput').value=em;
    document.getElementById('fcPreviewIcon').textContent=em;
}
function selectColor(c){
    document.getElementById('fcColorInput').value=c||'#FFD700';
    document.getElementById('fcPreviewIcon').style.color=c||'';
    document.getElementById('fcPreviewName').style.color=c||'';
}
document.addEventListener('DOMContentLoaded',()=>{
    const ci=document.getElementById('fcColorInput');
    if(ci) ci.addEventListener('input',e=>{document.getElementById('fcPreviewIcon').style.color=e.target.value;document.getElementById('fcPreviewName').style.color=e.target.value;});
    const ii=document.getElementById('fcIconInput');
    if(ii) ii.addEventListener('input',e=>{document.getElementById('fcPreviewIcon').textContent=e.target.value||'📁';});
});
function saveFolderMeta(){
    const icon=document.getElementById('fcIconInput').value;
    const color=document.getElementById('fcColorInput').value;
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/folder_meta.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast('Folder style saved!','success');closeModal('folderColorModal');setTimeout(()=>location.reload(),700);}else showToast(r.message,'error');};
    xhr.send('folder_key='+encodeURIComponent(fcFolderKey)+'&color='+encodeURIComponent(color)+'&icon='+encodeURIComponent(icon)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}
function resetFolderMeta(){
    const xhr=new XMLHttpRequest();
    xhr.open('POST','api/folder_meta.php',true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.onload=()=>{const r=JSON.parse(xhr.responseText);if(r.success){showToast('Reset!','success');closeModal('folderColorModal');setTimeout(()=>location.reload(),700);}};
    xhr.send('folder_key='+encodeURIComponent(fcFolderKey)+'&color=&icon=&csrf_token='+encodeURIComponent(CSRF_TOKEN));
}

// ══ STORAGE STATS MODAL ══
function showStatsModal(){
    openModal('statsModal');
    const el=document.getElementById('statsContent');
    el.innerHTML='<div style="padding:30px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    // Build stats from PHP-injected data (calculate per root-level folder recursively is too slow in JS, show overall + folder list)
    const totalMB=(STORAGE_USED/1024/1024).toFixed(2);
    const limitMB=5120; // 5GB soft limit display
    const pct=Math.min(100,((STORAGE_USED/1024/1024/limitMB)*100)).toFixed(1);
    el.innerHTML=`
        <div style="padding:18px 22px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:7px;">
                <span style="color:var(--text-dim);">Total Storage Used</span>
                <span style="color:var(--gold);font-weight:700;">${totalMB} MB</span>
            </div>
            <div style="height:8px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden;margin-bottom:18px;">
                <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--maroon),var(--maroon-bright));border-radius:4px;"></div>
            </div>
            <p style="font-size:12px;color:var(--text-muted);text-align:center;">
                Folder breakdown requires refreshing. Use the activity log to track uploads.
            </p>
        </div>`;
}

// ══ CUSTOM CONFIRM ══
let _confirmCallback = null;
function customConfirm(msg, onYes, confirmText='Confirm', danger=true, iconCls='fa-exclamation-triangle', sub=''){
    _confirmCallback = onYes;
    document.getElementById('confirmMsg').textContent = msg;
    document.getElementById('confirmSub').textContent = sub;
    document.getElementById('confirmIcon').textContent = danger ? '⚠️' : 'ℹ️';
    const btn = document.getElementById('confirmOkBtn');
    btn.innerHTML = '<i class="fas fa-check"></i> ' + confirmText;
    btn.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
    openModal('confirmModal');
}
function confirmResolve(yes){
    closeModal('confirmModal');
    if(yes && _confirmCallback) _confirmCallback();
    _confirmCallback = null;
}

// ══ FULLSCREEN IMAGE ══
function toggleFullscreen(){
    const modal = document.getElementById('previewModal');
    const isFs = modal.classList.toggle('fs-mode');
    const btns = document.querySelectorAll('#previewBody button');
    btns.forEach(b=>{ if(b.onclick && b.getAttribute('onclick') && b.getAttribute('onclick').includes('toggleFullscreen'))
        b.innerHTML = isFs ? '<i class="fas fa-compress"></i> Exit Full' : '<i class="fas fa-expand"></i> Fullscreen';
    });
}

// ══ COPY FILE PATH ══
function copyFilePath(filename,folderOverride){
    const folder = (folderOverride!==undefined&&folderOverride!==null)?folderOverride:(new URLSearchParams(window.location.search).get('folder')||'');
    const path = 'uploads/'+(folder?folder+'/':'')+filename;
    if(navigator.clipboard) navigator.clipboard.writeText(path).then(()=>showToast('Path copied!','success'));
    else fallbackCopy(path);
}

// ══ GLOBAL SEARCH ══
let gsearchTimer = null;
function showGlobalSearch(){
    document.getElementById('globalSearchInput').value = '';
    document.getElementById('globalSearchResults').innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);">Type to search across all folders…</div>';
    openModal('globalSearchModal');
    setTimeout(()=>document.getElementById('globalSearchInput').focus(), 120);
}
function onGlobalSearchInput(){
    clearTimeout(gsearchTimer);
    const q = document.getElementById('globalSearchInput').value.trim();
    if(q.length < 2){ document.getElementById('globalSearchResults').innerHTML='<div style="padding:24px;text-align:center;color:var(--text-muted);">Type at least 2 characters…</div>'; return; }
    gsearchTimer = setTimeout(doGlobalSearch, 350);
}
function doGlobalSearch(){
    const q = document.getElementById('globalSearchInput').value.trim();
    if(q.length < 2) return;
    const el = document.getElementById('globalSearchResults');
    const btn = document.getElementById('globalSearchBtn');
    el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';
    btn.disabled = true;
    fetch('api/search.php?q='+encodeURIComponent(q))
        .then(r=>r.json()).then(r=>{
            btn.disabled = false;
            if(!r.success){ el.innerHTML=`<div style="padding:20px;text-align:center;color:#e07070;">${escapeHtml(r.message||'Error')}</div>`; return; }
            if(!r.results.length){ el.innerHTML=`<div style="padding:24px;text-align:center;color:var(--text-muted);">No results for "<strong style="color:var(--text);">${escapeHtml(q)}</strong>"</div>`; return; }
            el.innerHTML = r.results.map(f=>{
                const icon = f.is_dir ? '📁' : getFileIconEmoji(f.ext);
                const destFolder = f.is_dir ? (f.folder?(f.folder+'/'+f.name):f.name) : f.folder;
                const href = 'dashboard.php' + (destFolder ? '?folder='+encodeURIComponent(destFolder) : '');
                const sizeStr = f.size > 0 ? formatBytes(f.size) : (f.is_dir ? 'Folder' : '');
                return `<div class="gsearch-result" onclick="closeModal('globalSearchModal');window.location='${href}'">
                    <span style="font-size:20px;flex-shrink:0;">${icon}</span>
                    <div style="min-width:0;flex:1;">
                        <div class="gsearch-result-name">${escapeHtml(f.name)}</div>
                        <div class="gsearch-result-path">${escapeHtml(f.folder||'root')}</div>
                    </div>
                    <span style="font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;">${sizeStr}</span>
                </div>`;
            }).join('');
        }).catch(()=>{ btn.disabled=false; el.innerHTML='<div style="padding:20px;color:#e07070;">Search failed.</div>'; });
}
function getFileIconEmoji(ext){
    const m={jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️',webp:'🖼️',svg:'🖼️',pdf:'📕',doc:'📄',docx:'📄',txt:'📝',md:'📝',zip:'📦',rar:'📦',mp3:'🎵',wav:'🎵',mp4:'🎬',mkv:'🎬',json:'📋',csv:'📊',xls:'📊',xlsx:'📊'};
    return m[ext]||'📄';
}

// ══ KEYBOARD: Ctrl+F = global search ══
document.addEventListener('keydown', e=>{
    if((e.ctrlKey||e.metaKey) && e.key==='f' && !document.querySelector('.modal-overlay.open')){
        e.preventDefault(); showGlobalSearch();
    }
});

