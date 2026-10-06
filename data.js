/* ==========================================================
   DATA.JS
   This file is loaded by every page. It holds:
     1. The starting (seed) data for a brand new install
     2. A "migrate" step that upgrades data saved by older versions of this site
     3. Storage helpers (DB) and login-session helpers (Session)
     4. Run — remembers an exam in progress so a page refresh doesn't lose it
     5. Attempts — works out how many tries a student has left on an exam
     6. Q — everything about question types: creating, checking, and grading them
     7. Small helpers used on every page (esc, $, requireRole, renderNav, tab)
   ========================================================== */

const KEY = 'exam_system_db_v3'; // localStorage key where all app data lives


/* ==========================================================
   1. default data
   ========================================================== */
const DEFAULT_DB = {
  users: [
    { id: 1, username: 'student1', password: 'password', role: 'student', name: 'Steven Ace Alcantara' },
    { id: 2, username: 'faculty1', password: 'password', role: 'faculty', name: 'Dr. Lem Llabres' },
    { id: 3, username: 'admin1',   password: 'password', role: 'admin',   name: 'System Administrator' },
    { id: 4, username: 'faculty2', password: 'password', role: 'faculty', name: 'Prof. Dan Gala' },
    { id: 5, username: 'student2', password: 'password', role: 'student', name: 'Matthew Besa' }
  ],

  // Each subject can have several faculty (facultyIds) and several enrolled students (studentIds)
  subjects: [
    { id: 101, code: 'IT101', name: 'IT Elective', description: 'test', facultyIds: [2], studentIds: [1] },
    { id: 102, code: 'DS201', name: 'Data Structures & Algorithms', description: 'Lorem Ipsum', facultyIds: [2], studentIds: [1] },
    { id: 103, code: 'SE301', name: 'Information Management', description: 'test', facultyIds: [2, 4], studentIds: [1] }
  ],

  // allowReview = can the student see their answers after submitting?
  // password   = access code the student must enter to start (blank = no code)
  // Each question has a "type" (mcq / checkbox / identification / dragdrop) — see section 6 below.
  exams: [
    { id: 1001, subjectId: 101, title: 'IT101 Midterm Examination', duration: 30, passingGrade: 60, maxAttempts: 2, shuffle: false, allowReview: true, password: '', questions: [
      { id: 'q1', type: 'mcq', text: 'Test Question', options: [{id:'o1',text:'Wrong'},{id:'o2',text:'Also Wrong'},{id:'o3',text:'Correct',isCorrect:true},{id:'o4',text:'This one also wrong'}] },
      
    ]},
    { id: 1002, subjectId: 102, title: 'DS201 Comprehensive Quiz', duration: 20, passingGrade: 70, maxAttempts: 1, shuffle: true, allowReview: true, password: '', questions: [
      { id: 'q1', type: 'checkbox', text: 'This is a checkbox question', options: [{id:'o1',text:'Correct',isCorrect:true},{id:'o2',text:'Also Correct',isCorrect:true},{id:'o3',text:'Correct too',isCorrect:true},{id:'o4',text:'This one wrong'}] },
      { id: 'q2', type: 'identification', text: 'This is Identification. Answer is "Yes"', answers: ['Yes'] },
      { id: 'q3', type: 'dragdrop', text: 'Dragdrop question', pairs: [
        { id: 'p1', left: '1', right: 'One' },
        { id: 'p2', left: '2', right: 'Two' },
        { id: 'p3', left: '3', right: 'Three' }
      ]}
    ]}
  ],

  // A record of one finished exam. "detail" (added when an exam is submitted) holds
  // each question plus the student's answer, so the review page can show it later.
  attempts: [],

  // Extra attempts a faculty member has manually granted a student, on top of the exam's normal limit
  retakes: []   // each entry looks like: { studentId, examId, granted }
};


/* ==========================================================
   2. UPGRADE OLDER SAVED DATA
   If someone already has data saved from an earlier version of this site, this
   function fills in any new fields it's missing, so nothing breaks.
   ========================================================== */

function migrate(db) {
  db.retakes = db.retakes || [];

  // Older data stored one faculty per subject (facultyId). Convert it to a list (facultyIds).
  db.subjects.forEach(s => {
    if (!s.facultyIds) s.facultyIds = s.facultyId != null ? [s.facultyId] : [];
    delete s.facultyId;
  });

  // Fill in exam settings that didn't exist before (attempt limit, shuffle, review, access code)
  db.exams.forEach(e => {
    if (e.maxAttempts == null) e.maxAttempts = 1;
    if (e.shuffle == null) e.shuffle = false;
    if (e.allowReview == null) e.allowReview = true;
    if (e.password == null) e.password = '';
    // Older questions had no "type" field — they were all multiple choice.
    e.questions.forEach(q => { if (!q.type) q.type = 'mcq'; });
  });

  return db;
}


/* ==========================================================
   3. STORAGE & LOGIN SESSION
   DB      = the whole app's data (users, subjects, exams, attempts), saved in localStorage
             so it survives closing the browser.
   Session = which user is currently logged in, saved in sessionStorage so it clears
             when the browser tab closes.
   ========================================================== */
const DB = {
  // Load saved data, or start fresh with DEFAULT_DB if nothing is saved yet
  load() {
    let db = null;
    try {
      const raw = localStorage.getItem(KEY);
      if (raw) db = JSON.parse(raw);
    } catch (e) {
      // Ignore a corrupted save and fall back to the defaults below
    }
    return migrate(db || structuredClone(DEFAULT_DB));
  },
  save(db) {
    localStorage.setItem(KEY, JSON.stringify(db));
  }
};

const Session = {
  get()   { try { return JSON.parse(sessionStorage.getItem('exam_user')); } catch (e) { return null; } },
  set(u)  { sessionStorage.setItem('exam_user', JSON.stringify(u)); },
  clear() { sessionStorage.removeItem('exam_user'); }
};


/* ==========================================================
   4. "RUN" — AN EXAM IN PROGRESS
   While a student is taking an exam, their answers, flags, current question and
   the exam's exact end time are all saved here under a key unique to that
   student + exam. This is what makes the timer survive a page refresh: we
   don't count down from scratch, we just compare "now" to the saved end time.
   ========================================================== */
   
const Run = {
  key: (uid, eid) => `exam_run_${uid}_${eid}`,
  get(uid, eid) { try { return JSON.parse(localStorage.getItem(Run.key(uid, eid))); } catch (e) { return null; } },
  set(uid, eid, run) { localStorage.setItem(Run.key(uid, eid), JSON.stringify(run)); },
  clear(uid, eid) { localStorage.removeItem(Run.key(uid, eid)); } // called once the exam is submitted
};


/* ==========================================================
   5. ATTEMPT LIMITS
   Works out, for one student and one exam:
     used    - how many times they've already completed it
     bonus   - extra attempts a faculty member has granted (see "retakes" above)
     allowed - the exam's normal limit, plus any bonus
     left    - how many attempts remain right now
   ========================================================== */
const Attempts = {
  used:    (db, sid, eid) => db.attempts.filter(a => a.studentId === sid && a.examId === eid).length,
  bonus:   (db, sid, eid) => db.retakes.filter(r => r.studentId === sid && r.examId === eid).reduce((t, r) => t + r.granted, 0),
  allowed: (db, sid, exam) => exam.maxAttempts + Attempts.bonus(db, sid, exam.id),
  left:    (db, sid, exam) => Math.max(0, Attempts.allowed(db, sid, exam) - Attempts.used(db, sid, exam.id))
};


/* ==========================================================
   6. QUESTION TYPES
   There are 4 kinds of question. Each one needs its own way to:
     - be created blank (make)
     - be checked for mistakes while an instructor is editing it (problem)
     - know whether a student has answered it at all (answered)
     - be graded against the student's answer (isCorrect)

   mcq            - pick one option, one of them is marked correct
   checkbox       - pick one or more options, several can be marked correct
   identification - type a short answer; it's matched against a list of accepted answers
   dragdrop       - match each "left" item to its correct "right" item
   ========================================================== */
const Q = {
  types: {
    mcq:            'Multiple Choice',
    checkbox:       'Checkbox',
    identification: 'Identification',
    dragdrop:       'Drag & Drop'
  },

  // Cleans up text before comparing identification answers, so "Queue", " queue ", and "QUEUE" all count as the same answer.
  norm: s => String(s ?? '').trim().toLowerCase().replace(/\s+/g, ' '),

  // Returns a blank question of the given type, ready to edit
  make(type, text = 'New Question Prompt?') {
    const id = 'q_' + Date.now() + Math.floor(Math.random() * 1000);
    if (type === 'checkbox') {
      return { id, type, text, options: [{id:'o1',text:'Option A',isCorrect:true},{id:'o2',text:'Option B',isCorrect:true},{id:'o3',text:'Option C'}] };
    }
    if (type === 'identification') {
      return { id, type, text, answers: [''] };
    }
    if (type === 'dragdrop') {
      return { id, type, text, pairs: [{id:'p1',left:'Item 1',right:'Match 1'},{id:'p2',left:'Item 2',right:'Match 2'}] };
    }
    return { id, type: 'mcq', text, options: [{id:'o1',text:'True',isCorrect:true},{id:'o2',text:'False'}] }; // Default to mcq if unknown type
  },

  // Used by the exam editor before saving. Returns a short message describing what's
  // wrong with the question, or '' if it's fine to save.
  problem(q) {
    if (!q.text.trim()) return 'has no question text';
    if (q.type === 'identification') return q.answers.some(a => a.trim()) ? '' : 'needs at least one accepted answer';
    if (q.type === 'dragdrop') return q.pairs.length >= 2 && q.pairs.every(p => p.left.trim() && p.right.trim()) ? '' : 'needs at least 2 complete pairs';
    if (!q.options.every(o => o.text.trim())) return 'has an empty option';
    if (!q.options.some(o => o.isCorrect)) return 'needs a correct option';
    return '';
  },

  // Has the student given any answer to this question at all? (used for the
  // "answered/unanswered" counts, not for grading)
  answered(q, a) {
    if (a == null) return false;
    if (q.type === 'checkbox') return a.length > 0;
    if (q.type === 'identification') return String(a).trim() !== '';
    if (q.type === 'dragdrop') return Object.keys(a).length > 0;
    return true;
  },

  // Grades one question. Every question is all-or-nothing: partially right earns no credit.
  isCorrect(q, a) {
    if (!Q.answered(q, a)) return false;

    if (q.type === 'checkbox') {
      // Student's selected ids must exactly match the set of correct ids
      const right = q.options.filter(o => o.isCorrect).map(o => o.id).sort();
      return JSON.stringify([...a].sort()) === JSON.stringify(right);
    }
    if (q.type === 'identification') {
      // Correct if it matches ANY of the accepted answers
      return q.answers.some(x => Q.norm(x) === Q.norm(a));
    }
    if (q.type === 'dragdrop') {
      // Correct if every pair was matched to its correct right-hand side
      return q.pairs.every(p => {
        const given = q.pairs.find(x => x.id === a[p.id]);
        return given && Q.norm(given.right) === Q.norm(p.right);
      });
    }
    // mcq: the chosen option id must be the one marked correct
    const right = q.options.find(o => o.isCorrect);
    return !!right && a === right.id;
  }
};


/* ==========================================================
   7. SHARED PAGE HELPERS
   Small functions used by every page in the site.
   ========================================================== */

const HOME = { student: 'student.html', faculty: 'faculty.html', admin: 'admin.html' }; // where each role lands after login
const $ = id => document.getElementById(id); // shortcut for document.getElementById

// Escapes text before inserting it into HTML, so a title or answer containing
// characters like < or " can't break the page or inject code.
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Put this at the top of every page that needs a specific role logged in.
// Sends the visitor back to the login page if they're not signed in, or signed
// in as the wrong role (e.g. a student opening admin.html directly).
function requireRole(...roles) {
  const u = Session.get();
  if (!u || !roles.includes(u.role)) {
    location.href = 'index.html';
    throw new Error('redirecting'); // stops the rest of the page's script from running
  }
  return u;
}

function logout() {
  Session.clear();
  location.href = 'index.html';
}

// Draws the top navigation bar: logo, page links, the user's name/role, and a sign-out button.
function renderNav(user, links = []) {
  document.head.insertAdjacentHTML('beforeend', '<link rel="icon" href="Logo.png">'); // browser tab icon
  document.body.insertAdjacentHTML('afterbegin', `
    <header class="nav">
      <div class="brand">
        <span class="logo-img"><img src="Logo.png" alt="Mateo University"></span>ExamPortal
        ${links.map(([h, t]) => `<a href="${h}">${t}</a>`).join('')}
      </div>
      <div class="row"><span><b>${esc(user.name)}</b> <span class="muted">${user.role}</span></span>
        <button class="btn ghost" onclick="logout()">Sign Out</button></div>
    </header>`);
}

// Switches which .pane is visible and highlights the matching tab button.
// Used by every page that has tabs (e.g. faculty.html's "Courses / Students / Retakes" tabs).
function tab(name) {
  document.querySelectorAll('.pane').forEach(p => p.classList.toggle('active', p.id === name));
  document.querySelectorAll('.tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
}