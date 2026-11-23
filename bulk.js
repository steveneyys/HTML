/* ==========================================================
   Bulk assignment tools (admin + assign.html)
   - BulkTools.sections: tick many students, put them in a section at once.
   - BulkTools.enroll:   tick whole sections and/or many students, enroll them
                         in a subject at once (or remove them at once).
   Needs data.js (esc, Sections, DB) to be loaded first.
   ========================================================== */
const BulkTools = (() => {
  const css = `
  .bulk-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin:10px 0}
  .bulk-bar>div{min-width:160px;flex:1}
  .bulk-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:12px;padding:10px 12px;border:1px solid var(--border,#d9dde3);border-radius:10px;background:rgba(127,127,127,.06)}
  .bulk-actions .grow{flex:1}
  .bulk-scroll{max-height:380px;overflow:auto;border:1px solid var(--border,#d9dde3);border-radius:10px}
  .bulk-scroll table{margin:0}
  .bulk-scroll thead th{position:sticky;top:0;background:var(--card,#fff);z-index:1}
  .bulk-secs{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 4px}
  .bulk-sec{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--border,#d9dde3);border-radius:999px;cursor:pointer;font-size:14px}
  .bulk-sec input,.bulk-tbl input[type=checkbox]{width:auto;margin:0}
  .bulk-sec.on{border-color:#2563eb;background:rgba(37,99,235,.1)}`;
  if (!document.getElementById('bulk-css')) {
    const st = document.createElement('style');
    st.id = 'bulk-css';
    st.textContent = css;
    document.head.appendChild(st);
  }

  const fmt = n => `${n} selected`;

  function sections(el, opts) {
    const { db, students, save, onChange } = opts;
    const picked = new Set();
    let q = '', filter = 'all';

    const visible = () => students().filter(s => {
      if (q && !(`${s.name} ${s.username}`.toLowerCase().includes(q))) return false;
      const sec = Sections.of(db, s.id);
      if (filter === 'all') return true;
      if (filter === 'none') return !sec;
      return !!sec && String(sec.id) === filter;
    });

    function draw() {
      const list = visible();
      const allStudentIds = new Set(students().map(s => s.id));
      [...picked].forEach(id => { if (!allStudentIds.has(id)) picked.delete(id); });
      const allTicked = list.length > 0 && list.every(s => picked.has(s.id));

      el.innerHTML = `
        <div class="bulk-bar">
          <div><label>Search</label><input data-r="q" placeholder="Name or username" value="${esc(q)}"></div>
          <div><label>Show</label>
            <select data-r="filter">
              <option value="all">All students</option>
              <option value="none" ${filter === 'none' ? 'selected' : ''}>Not in a section</option>
              ${db.sections.map(s => `<option value="${s.id}" ${filter === String(s.id) ? 'selected' : ''}>In ${esc(s.name)}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="bulk-scroll"><table class="bulk-tbl">
          <thead><tr>
            <th style="width:36px"><input type="checkbox" data-r="all" aria-label="Select all shown" ${allTicked ? 'checked' : ''}></th>
            <th>Student</th><th>Username</th><th>Section</th></tr></thead>
          <tbody>${list.map(s => {
            const sec = Sections.of(db, s.id);
            return `<tr>
              <td><input type="checkbox" data-r="row" value="${s.id}" ${picked.has(s.id) ? 'checked' : ''} aria-label="Select ${esc(s.name || '')}"></td>
              <td><b>${esc(s.name || '')}</b></td>
              <td class="muted">${esc(s.username || '')}</td>
              <td>${sec ? esc(sec.name) : '<span class="muted">None</span>'}</td></tr>`;
          }).join('') || '<tr><td colspan="4" class="muted">No students match.</td></tr>'}</tbody>
        </table></div>
        <div class="bulk-actions">
          <b>${fmt(picked.size)}</b>
          <span class="grow"></span>
          <select data-r="target" aria-label="Target section" style="width:auto">
            ${db.sections.length
              ? db.sections.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('')
              : '<option value="">No sections yet</option>'}
          </select>
          <button class="btn ok sm" type="button" data-r="assign" ${picked.size && db.sections.length ? '' : 'disabled'}>Move to section</button>
          <button class="btn ghost sm" type="button" data-r="remove" ${picked.size ? '' : 'disabled'}>Remove from section</button>
        </div>`;

      const q$ = el.querySelector('[data-r=q]');
      q$.addEventListener('input', () => {
        q = q$.value.trim().toLowerCase();
        const pos = q$.selectionStart;
        draw();
        const n = el.querySelector('[data-r=q]'); n.focus(); n.setSelectionRange(pos, pos);
      });
      el.querySelector('[data-r=filter]').addEventListener('change', e => { filter = e.target.value; draw(); });
      el.querySelector('[data-r=all]').addEventListener('change', e => {
        list.forEach(s => e.target.checked ? picked.add(s.id) : picked.delete(s.id));
        draw();
      });
      el.querySelectorAll('[data-r=row]').forEach(c => c.addEventListener('change', () => {
        const id = Number(c.value);
        c.checked ? picked.add(id) : picked.delete(id);
        draw();
      }));
      el.querySelector('[data-r=assign]').addEventListener('click', () => {
        const target = Number(el.querySelector('[data-r=target]').value);
        if (!target) return;
        const sec = db.sections.find(s => s.id === target);
        picked.forEach(id => Sections.assign(db, id, target));
        const n = picked.size;
        picked.clear();
        if (!save()) return;
        alert(`${n} student${n === 1 ? '' : 's'} moved to ${sec.name}.`);
        draw(); onChange && onChange();
      });
      el.querySelector('[data-r=remove]').addEventListener('click', () => {
        const n = picked.size;
        if (!confirm(`Remove ${n} student${n === 1 ? '' : 's'} from their section?`)) return;
        picked.forEach(id => Sections.assign(db, id, null));
        picked.clear();
        if (!save()) return;
        draw(); onChange && onChange();
      });
    }
    draw();
    return { redraw: draw };
  }

  function enroll(el, opts) {
    const { db, students, subjects, canEdit, save, onChange } = opts;
    const picked = new Set();
    let q = '', filter = 'all', subjectId = '';

    const secStudentIds = sec =>
      students().filter(s => sec.studentIds.includes(s.id)).map(s => s.id);

    const visible = () => students().filter(s => {
      if (q && !(`${s.name} ${s.username}`.toLowerCase().includes(q))) return false;
      const sec = Sections.of(db, s.id);
      if (filter === 'all') return true;
      if (filter === 'none') return !sec;
      return !!sec && String(sec.id) === filter;
    });

    function draw() {
      const subs = subjects();
      if (!subs.some(s => String(s.id) === subjectId)) subjectId = subs[0] ? String(subs[0].id) : '';
      const sub = subs.find(s => String(s.id) === subjectId);
      const editable = sub ? canEdit(sub.id) : false;
      const list = visible();
      const ids = new Set(students().map(s => s.id));
      [...picked].forEach(id => { if (!ids.has(id)) picked.delete(id); });
      const allTicked = list.length > 0 && list.every(s => picked.has(s.id));

      el.innerHTML = `
        <div class="bulk-bar">
          <div><label>Subject</label>
            <select data-r="subject">${subs.map(s => `<option value="${s.id}" ${String(s.id) === subjectId ? 'selected' : ''}>${esc(s.code)} - ${esc(s.name)}</option>`).join('') || '<option value="">No subjects</option>'}</select>
          </div>
        </div>
        <label>Pick whole sections <span class="muted" style="font-weight:400">(ticks every student in the section)</span></label>
        <div class="bulk-secs">${db.sections.map(sec => {
          const sIds = secStudentIds(sec);
          const on = sIds.length > 0 && sIds.every(id => picked.has(id));
          return `<label class="bulk-sec ${on ? 'on' : ''}"><input type="checkbox" data-r="sec" value="${sec.id}" ${on ? 'checked' : ''} ${sIds.length ? '' : 'disabled'}> ${esc(sec.name)} <span class="muted">(${sIds.length})</span></label>`;
        }).join('') || '<span class="muted">No sections yet.</span>'}</div>
        <div class="bulk-bar">
          <div><label>Search students</label><input data-r="q" placeholder="Name or username" value="${esc(q)}"></div>
          <div><label>Show</label>
            <select data-r="filter">
              <option value="all">All students</option>
              <option value="none" ${filter === 'none' ? 'selected' : ''}>Not in a section</option>
              ${db.sections.map(s => `<option value="${s.id}" ${filter === String(s.id) ? 'selected' : ''}>In ${esc(s.name)}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="bulk-scroll"><table class="bulk-tbl">
          <thead><tr>
            <th style="width:36px"><input type="checkbox" data-r="all" aria-label="Select all shown" ${allTicked ? 'checked' : ''}></th>
            <th>Student</th><th>Username</th><th>Section</th><th>In this subject?</th></tr></thead>
          <tbody>${list.map(s => {
            const sec = Sections.of(db, s.id);
            const inSub = sub && sub.studentIds.includes(s.id);
            return `<tr>
              <td><input type="checkbox" data-r="row" value="${s.id}" ${picked.has(s.id) ? 'checked' : ''} aria-label="Select ${esc(s.name || '')}"></td>
              <td><b>${esc(s.name || '')}</b></td>
              <td class="muted">${esc(s.username || '')}</td>
              <td>${sec ? esc(sec.name) : '<span class="muted">None</span>'}</td>
              <td>${inSub ? 'Enrolled' : '<span class="muted">No</span>'}</td></tr>`;
          }).join('') || '<tr><td colspan="5" class="muted">No students match.</td></tr>'}</tbody>
        </table></div>
        <div class="bulk-actions">
          <b>${fmt(picked.size)}</b>
          ${editable ? '' : '<span class="muted">You can only change subjects you teach.</span>'}
          <span class="grow"></span>
          <button class="btn ok sm" type="button" data-r="enroll" ${picked.size && editable ? '' : 'disabled'}>Enroll selected</button>
          <button class="btn ghost sm" type="button" data-r="unenroll" ${picked.size && editable ? '' : 'disabled'}>Remove selected</button>
        </div>`;

      el.querySelector('[data-r=subject]').addEventListener('change', e => { subjectId = e.target.value; draw(); });
      const q$ = el.querySelector('[data-r=q]');
      q$.addEventListener('input', () => {
        q = q$.value.trim().toLowerCase();
        const pos = q$.selectionStart;
        draw();
        const n = el.querySelector('[data-r=q]'); n.focus(); n.setSelectionRange(pos, pos);
      });
      el.querySelector('[data-r=filter]').addEventListener('change', e => { filter = e.target.value; draw(); });
      el.querySelector('[data-r=all]').addEventListener('change', e => {
        list.forEach(s => e.target.checked ? picked.add(s.id) : picked.delete(s.id));
        draw();
      });
      el.querySelectorAll('[data-r=row]').forEach(c => c.addEventListener('change', () => {
        const id = Number(c.value);
        c.checked ? picked.add(id) : picked.delete(id);
        draw();
      }));
      el.querySelectorAll('[data-r=sec]').forEach(c => c.addEventListener('change', () => {
        const sec = db.sections.find(s => s.id === Number(c.value));
        secStudentIds(sec).forEach(id => c.checked ? picked.add(id) : picked.delete(id));
        draw();
      }));
      el.querySelector('[data-r=enroll]').addEventListener('click', () => {
        if (!sub || !editable) return;
        let added = 0;
        picked.forEach(id => { if (!sub.studentIds.includes(id)) { sub.studentIds.push(id); added++; } });
        const skipped = picked.size - added;
        picked.clear();
        if (!save()) return;
        alert(`${added} student${added === 1 ? '' : 's'} enrolled in ${sub.code}.` + (skipped ? ` ${skipped} already enrolled.` : ''));
        draw(); onChange && onChange();
      });
      el.querySelector('[data-r=unenroll]').addEventListener('click', () => {
        if (!sub || !editable) return;
        const n = picked.size;
        if (!confirm(`Remove ${n} student${n === 1 ? '' : 's'} from ${sub.code}? Their past attempts are kept.`)) return;
        sub.studentIds = sub.studentIds.filter(id => !picked.has(id));
        picked.clear();
        if (!save()) return;
        draw(); onChange && onChange();
      });
    }
    draw();
    return { redraw: draw };
  }

  return { sections, enroll };
})();
