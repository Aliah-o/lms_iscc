<?php
define('ROLES', [
    'superadmin' => 'Superadmin',
    'staff'      => 'Staff',
    'instructor'    => 'Instructor',
    'student'    => 'Student',
]);

define('PROGRAMS', [
    'BSIT'  => 'Bachelor of Science in Information Technology',
    'BSTM'  => 'Bachelor of Science in Tourism Management',
    'BSHM'  => 'Bachelor of Science in Hospitality Management',
    'BSM'   => 'Bachelor of Science in Midwifery',
    'BSCM'   => 'Bachelor of Science in Cooperative Management',
]);

define('YEAR_LEVELS', [
    1 => '1st Year',
    2 => '2nd Year',
    3 => '3rd Year',
    4 => '4th Year',
]);

define('SECTIONS_PER_YEAR', [
    1 => ['A', 'B', 'C'],
    2 => ['A', 'B', 'C'],
    3 => ['A', 'B', 'C'],
    4 => ['A', 'B', 'C'],
]);

define('SEMESTERS', [
    'First Semester',
    'Second Semester',
    'Mid-Year',
]);

define('KNOWLEDGE_LEVELS', [
    'beginner'     => 'Beginner',
    'intermediate' => 'Intermediate',
    'advanced'     => 'Advanced',
]);
