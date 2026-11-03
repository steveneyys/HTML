/* ==========================================================
   DATA.JS
   Shared data, storage, login, grading, and question selection.
   ========================================================== */

const KEY = 'exam_system_db';

/* ==========================================================
   1. DEFAULT DATA
   ========================================================== */

const DEFAULT_DB = {
  users: [
    {
      id: 1,
      username: 'student1',
      password: 'password',
      role: 'student',
      name: 'Steven Ace Alcantara'
    },
    {
      id: 2,
      username: 'faculty1',
      password: 'password',
      role: 'faculty',
      name: 'Dr. Lem Llabres'
    },
    {
      id: 3,
      username: 'admin1',
      password: 'password',
      role: 'admin',
      name: 'System Administrator'
    },
    {
      id: 4,
      username: 'faculty2',
      password: 'password',
      role: 'faculty',
      name: 'Prof. Dan Gala'
    },
    {
      id: 5,
      username: 'student2',
      password: 'password',
      role: 'student',
      name: 'Matthew Besa'
    }
  ],

  subjects: [
    {
      id: 101,
      code: 'IT101',
      name: 'IT Elective',
      description: 'test',
      facultyIds: [2],
      studentIds: [1]
    },
    {
      id: 102,
      code: 'DS201',
      name: 'Data Structures & Algorithms',
      description: 'Lorem Ipsum',
      facultyIds: [2],
      studentIds: [1]
    },
    {
      id: 103,
      code: 'SE301',
      name: 'Information Management',
      description: 'test',
      facultyIds: [2, 4],
      studentIds: [1]
    }
  ],

  /*
    questionCount:
      0 = show all questions.
      Positive number = randomly select that many questions.

    shuffle:
      Controls order when showing the whole bank with questionCount = 0.
      A positive questionCount always uses random, shuffled selection.

    allowReview:
      Whether students can review submitted answers.

    password:
      Optional access code for the exam.
  */

  exams: [
    {
      id: 1001,
      subjectId: 101,
      title: 'IT101 Midterm Examination',
      duration: 30,
      passingGrade: 60,
      maxAttempts: 2,
      shuffle: false,
      allowReview: true,
      password: '',
      questionCount: 0,

      questions: [
        {
          id: 'q1',
          type: 'mcq',
          text: 'Test Question',
          options: [
            { id: 'o1', text: 'Wrong' },
            { id: 'o2', text: 'Also Wrong' },
            { id: 'o3', text: 'Correct', isCorrect: true },
            { id: 'o4', text: 'This one also wrong' }
          ]
        }
      ]
    },

    {
      id: 1002,
      subjectId: 102,
      title: 'DS201 Comprehensive Quiz',
      duration: 20,
      passingGrade: 70,
      maxAttempts: 1,
      shuffle: true,
      allowReview: true,
      password: '',
      questionCount: 0,

      questions: [
        {
          id: 'q1',
          type: 'checkbox',
          text: 'This is a checkbox question',
          options: [
            { id: 'o1', text: 'Correct', isCorrect: true },
            { id: 'o2', text: 'Also Correct', isCorrect: true },
            { id: 'o3', text: 'Correct too', isCorrect: true },
            { id: 'o4', text: 'This one wrong' }
          ]
        },
        {
          id: 'q2',
          type: 'identification',
          text: 'This is Identification. Answer is "Yes"',
          answers: ['Yes']
        },
        {
          id: 'q3',
          type: 'dragdrop',
          text: 'Dragdrop question',
          pairs: [
            { id: 'p1', left: '1', right: 'One' },
            { id: 'p2', left: '2', right: 'Two' },
            { id: 'p3', left: '3', right: 'Three' }
          ]
        }
      ]
    }
  ],

  // Completed attempts, including question snapshots and answers.
  attempts: [],

  // Additional attempts granted by a faculty member.
  retakes: []
};

/* ==========================================================
   2. UPGRADE OLDER SAVED DATA
   ========================================================== */

function migrate(db) {
  db.retakes = db.retakes || [];

  db.subjects.forEach(subject => {
    if (!subject.facultyIds) {
      subject.facultyIds = subject.facultyId != null
        ? [subject.facultyId]
        : [];
    }

    delete subject.facultyId;
  });

  db.exams.forEach(exam => {
    if (exam.maxAttempts == null) exam.maxAttempts = 1;
    if (exam.shuffle == null) exam.shuffle = false;
    if (exam.allowReview == null) exam.allowReview = true;
    if (exam.password == null) exam.password = '';

    // Existing exams continue to show all questions by default.
    if (exam.questionCount == null) exam.questionCount = 0;

    exam.questions.forEach(question => {
      if (!question.type) question.type = 'mcq';
    });
  });

  return db;
}

/* ==========================================================
   3. STORAGE AND LOGIN SESSION
   ========================================================== */

const DB = {
  load() {
    let db = null;

    try {
      const raw = localStorage.getItem(KEY);

      if (raw) {
        db = JSON.parse(raw);
      }
    } catch (error) {
      // Fall back to seed data if stored JSON cannot be read.
    }

    return migrate(db || structuredClone(DEFAULT_DB));
  },

  save(db) {
    localStorage.setItem(KEY, JSON.stringify(db));
  }
};

const Session = {
  get() {
    try {
      return JSON.parse(sessionStorage.getItem('exam_user'));
    } catch (error) {
      return null;
    }
  },

  set(user) {
    sessionStorage.setItem('exam_user', JSON.stringify(user));
  },

  clear() {
    sessionStorage.removeItem('exam_user');
  }
};

/* ==========================================================
   4. EXAM IN PROGRESS

   The exam page stores the selected questions, their order,
   answers, flags, current index, and end time in the run.
   ========================================================== */

const Run = {
  key: (uid, eid) => `exam_run_${uid}_${eid}`,

  get(uid, eid) {
    try {
      return JSON.parse(
        localStorage.getItem(Run.key(uid, eid))
      );
    } catch (error) {
      return null;
    }
  },

  set(uid, eid, run) {
    localStorage.setItem(
      Run.key(uid, eid),
      JSON.stringify(run)
    );
  },

  clear(uid, eid) {
    localStorage.removeItem(Run.key(uid, eid));
  }
};

/* ==========================================================
   5. ATTEMPT LIMITS
   ========================================================== */

const Attempts = {
  used: (db, sid, eid) =>
    db.attempts.filter(attempt =>
      attempt.studentId === sid &&
      attempt.examId === eid
    ).length,

  bonus: (db, sid, eid) =>
    db.retakes
      .filter(retake =>
        retake.studentId === sid &&
        retake.examId === eid
      )
      .reduce((total, retake) => total + retake.granted, 0),

  allowed: (db, sid, exam) =>
    exam.maxAttempts + Attempts.bonus(db, sid, exam.id),

  left: (db, sid, exam) =>
    Math.max(
      0,
      Attempts.allowed(db, sid, exam) -
      Attempts.used(db, sid, exam.id)
    )
};

/* ==========================================================
   6. QUESTION TYPES AND GRADING
   ========================================================== */

const Q = {
  types: {
    mcq: 'Multiple Choice',
    checkbox: 'Checkbox',
    identification: 'Identification',
    dragdrop: 'Drag & Drop'
  },

  // Ignore capitalization and extra whitespace.
  // Commas remain part of an answer.
  norm: value =>
    String(value ?? '')
      .trim()
      .toLowerCase()
      .replace(/\s+/g, ' '),

  make(type, text = 'New Question Prompt?') {
    const id =
      'q_' + Date.now() + Math.floor(Math.random() * 1000);

    if (type === 'checkbox') {
      return {
        id,
        type,
        text,
        options: [
          { id: 'o1', text: 'Option A', isCorrect: true },
          { id: 'o2', text: 'Option B', isCorrect: true },
          { id: 'o3', text: 'Option C' }
        ]
      };
    }

    if (type === 'identification') {
      // Each array entry is one accepted answer.
      // The editor displays each entry in its own input box.
      return {
        id,
        type,
        text,
        answers: ['']
      };
    }

    if (type === 'dragdrop') {
      return {
        id,
        type,
        text,
        pairs: [
          { id: 'p1', left: 'Item 1', right: 'Match 1' },
          { id: 'p2', left: 'Item 2', right: 'Match 2' }
        ]
      };
    }

    return {
      id,
      type: 'mcq',
      text,
      options: [
        { id: 'o1', text: 'True', isCorrect: true },
        { id: 'o2', text: 'False' }
      ]
    };
  },

  problem(question) {
    if (!question.text.trim()) {
      return 'has no question text';
    }

    if (question.type === 'identification') {
      return question.answers.some(answer => answer.trim())
        ? ''
        : 'needs at least one accepted answer';
    }

    if (question.type === 'dragdrop') {
      const valid =
        question.pairs.length >= 2 &&
        question.pairs.every(pair =>
          pair.left.trim() && pair.right.trim()
        );

      return valid ? '' : 'needs at least 2 complete pairs';
    }

    if (!question.options.every(option => option.text.trim())) {
      return 'has an empty option';
    }

    if (!question.options.some(option => option.isCorrect)) {
      return 'needs a correct option';
    }

    return '';
  },

  answered(question, answer) {
    if (answer == null) return false;

    if (question.type === 'checkbox') {
      return answer.length > 0;
    }

    if (question.type === 'identification') {
      return String(answer).trim() !== '';
    }

    if (question.type === 'dragdrop') {
      return Object.keys(answer).length > 0;
    }

    return true;
  },

  isCorrect(question, answer) {
    if (!Q.answered(question, answer)) return false;

    if (question.type === 'checkbox') {
      const correctIds = question.options
        .filter(option => option.isCorrect)
        .map(option => option.id)
        .sort();

      return JSON.stringify([...answer].sort()) ===
        JSON.stringify(correctIds);
    }

    if (question.type === 'identification') {
      // Matching any ONE accepted answer is enough.
      return question.answers.some(accepted =>
        Q.norm(accepted) === Q.norm(answer)
      );
    }

    if (question.type === 'dragdrop') {
      return question.pairs.every(pair => {
        const given = question.pairs.find(item =>
          item.id === answer[pair.id]
        );

        return given &&
          Q.norm(given.right) === Q.norm(pair.right);
      });
    }

    const correct = question.options.find(option =>
      option.isCorrect
    );

    return !!correct && answer === correct.id;
  }
};

/* ==========================================================
   7. SHARED PAGE HELPERS
   ========================================================== */

const HOME = {
  student: 'student.html',
  faculty: 'faculty.html',
  admin: 'admin.html'
};

const $ = id => document.getElementById(id);

const esc = value =>
  String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[character]));

function requireRole(...roles) {
  const user = Session.get();

  if (!user || !roles.includes(user.role)) {
    location.href = 'index.html';
    throw new Error('redirecting');
  }

  return user;
}

function logout() {
  Session.clear();
  location.href = 'index.html';
}

function renderNav(user, links = []) {
  document.head.insertAdjacentHTML(
    'beforeend',
    '<link rel="icon" href="Logo.png">'
  );

  document.body.insertAdjacentHTML('afterbegin', `
    <header class="nav">
      <div class="brand">
        <span class="logo-img">
          <img src="Logo.png" alt="Mateo University">
        </span>
        ExamPortal

        ${links.map(([href, text]) => `
          <a href="${href}">${text}</a>
        `).join('')}
      </div>

      <div class="row">
        <span>
          <b>${esc(user.name)}</b>
          <span class="muted">${user.role}</span>
        </span>

        <button class="btn ghost" onclick="logout()">
          Sign Out
        </button>
      </div>
    </header>
  `);
}

function tab(name) {
  document.querySelectorAll('.pane').forEach(pane => {
    pane.classList.toggle('active', pane.id === name);
  });

  document.querySelectorAll('.tabs button').forEach(button => {
    button.classList.toggle(
      'active',
      button.dataset.tab === name
    );
  });
}

/* ==========================================================
   8. RANDOM QUESTION SELECTION

   Faculty sets exam.questionCount:
     0 = all questions.
     1 or more = randomly select that many questions.

   The selected count cannot exceed the bank size.
   Random selection does not modify the question bank.
   ========================================================== */

const ExamQuestions = {
  valid(exam) {
    const count = Number(exam.questionCount ?? 0);

    return Number.isInteger(count) &&
      count >= 0 &&
      count <= exam.questions.length;
  },

  count(exam) {
    const count = Number(exam.questionCount ?? 0);

    return Number.isInteger(count) && count > 0
      ? Math.min(count, exam.questions.length)
      : exam.questions.length;
  },

  select(exam) {
    if (!this.valid(exam) || !exam.questions.length) {
      throw new Error(
        'Invalid number of questions. ' +
        'Ask your instructor to update the exam.'
      );
    }

    const questions = structuredClone(exam.questions);

    // A positive questionCount always selects in shuffled order.
    if (Number(exam.questionCount ?? 0) > 0 || exam.shuffle) {
      for (let k = questions.length - 1; k > 0; k--) {
        const j = Math.floor(Math.random() * (k + 1));

        [questions[k], questions[j]] =
          [questions[j], questions[k]];
      }
    }

    return questions.slice(0, this.count(exam));
  }
};