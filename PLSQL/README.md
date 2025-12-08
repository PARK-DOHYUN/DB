# PL/SQL 문법 정리

PL/SQL(Procedural Language/Structured Query Language)은 Oracle 데이터베이스에서 사용하는 절차적 프로그래밍 언어입니다.

## 기본 구조
```plsql
DECLARE
    -- 변수 선언부 (선택사항)
    v_name VARCHAR2(50);
    v_count NUMBER := 0;
BEGIN
    -- 실행부 (필수)
    SELECT name INTO v_name FROM employees WHERE id = 1;
    DBMS_OUTPUT.PUT_LINE('이름: ' || v_name);
EXCEPTION
    -- 예외 처리부 (선택사항)
    WHEN NO_DATA_FOUND THEN
        DBMS_OUTPUT.PUT_LINE('데이터가 없습니다.');
END;
/
```

## 변수 선언
```plsql
-- 기본 데이터 타입
v_number NUMBER := 100;
v_string VARCHAR2(100) := 'Hello';
v_date DATE := SYSDATE;
v_boolean BOOLEAN := TRUE;

-- %TYPE: 컬럼의 데이터 타입 참조
v_emp_name employees.name%TYPE;

-- %ROWTYPE: 테이블 전체 행의 구조 참조
v_emp_row employees%ROWTYPE;
```

## 조건문
```plsql
-- IF문
IF v_count > 10 THEN
    DBMS_OUTPUT.PUT_LINE('10보다 큽니다');
ELSIF v_count = 10 THEN
    DBMS_OUTPUT.PUT_LINE('10입니다');
ELSE
    DBMS_OUTPUT.PUT_LINE('10보다 작습니다');
END IF;

-- CASE문
CASE v_grade
    WHEN 'A' THEN DBMS_OUTPUT.PUT_LINE('우수');
    WHEN 'B' THEN DBMS_OUTPUT.PUT_LINE('양호');
    ELSE DBMS_OUTPUT.PUT_LINE('보통');
END CASE;
```

## 반복문
```plsql
-- LOOP
LOOP
    v_count := v_count + 1;
    EXIT WHEN v_count > 5;
END LOOP;

-- WHILE
WHILE v_count < 10 LOOP
    v_count := v_count + 1;
END LOOP;

-- FOR
FOR i IN 1..10 LOOP
    DBMS_OUTPUT.PUT_LINE(i);
END LOOP;

-- 역순 FOR
FOR i IN REVERSE 1..10 LOOP
    DBMS_OUTPUT.PUT_LINE(i);
END LOOP;
```

## 커서(Cursor)
```plsql
-- 명시적 커서
DECLARE
    CURSOR emp_cursor IS
        SELECT id, name FROM employees;
    v_id employees.id%TYPE;
    v_name employees.name%TYPE;
BEGIN
    OPEN emp_cursor;
    LOOP
        FETCH emp_cursor INTO v_id, v_name;
        EXIT WHEN emp_cursor%NOTFOUND;
        DBMS_OUTPUT.PUT_LINE(v_id || ' - ' || v_name);
    END LOOP;
    CLOSE emp_cursor;
END;

-- FOR 커서 루프 (간단한 방법)
FOR emp_rec IN (SELECT id, name FROM employees) LOOP
    DBMS_OUTPUT.PUT_LINE(emp_rec.id || ' - ' || emp_rec.name);
END LOOP;
```

## 저장 프로시저(Procedure)
```plsql
CREATE OR REPLACE PROCEDURE update_salary(
    p_emp_id IN NUMBER,
    p_amount IN NUMBER
) IS
BEGIN
    UPDATE employees
    SET salary = salary + p_amount
    WHERE id = p_emp_id;
    COMMIT;
EXCEPTION
    WHEN OTHERS THEN
        ROLLBACK;
        RAISE;
END;
/

-- 프로시저 실행
BEGIN
    update_salary(101, 5000);
END;
```

## 함수(Function)
```plsql
CREATE OR REPLACE FUNCTION get_employee_name(
    p_emp_id IN NUMBER
) RETURN VARCHAR2 IS
    v_name VARCHAR2(100);
BEGIN
    SELECT name INTO v_name
    FROM employees
    WHERE id = p_emp_id;
    
    RETURN v_name;
EXCEPTION
    WHEN NO_DATA_FOUND THEN
        RETURN NULL;
END;
/

-- 함수 사용
SELECT get_employee_name(101) FROM DUAL;
```

## 트리거(Trigger)
```plsql
CREATE OR REPLACE TRIGGER trg_before_insert
BEFORE INSERT ON employees
FOR EACH ROW
BEGIN
    :NEW.created_date := SYSDATE;
    :NEW.id := emp_seq.NEXTVAL;
END;
/
```

## 예외 처리
```plsql
DECLARE
    v_name VARCHAR2(50);
BEGIN
    SELECT name INTO v_name FROM employees WHERE id = 999;
EXCEPTION
    WHEN NO_DATA_FOUND THEN
        DBMS_OUTPUT.PUT_LINE('데이터를 찾을 수 없습니다');
    WHEN TOO_MANY_ROWS THEN
        DBMS_OUTPUT.PUT_LINE('여러 행이 반환되었습니다');
    WHEN OTHERS THEN
        DBMS_OUTPUT.PUT_LINE('오류 발생: ' || SQLERRM);
END;
```

## 패키지(Package)
```plsql
-- 패키지 명세(Specification)
CREATE OR REPLACE PACKAGE emp_pkg IS
    PROCEDURE hire_employee(p_name VARCHAR2, p_salary NUMBER);
    FUNCTION get_total_salary RETURN NUMBER;
END emp_pkg;
/

-- 패키지 본문(Body)
CREATE OR REPLACE PACKAGE BODY emp_pkg IS
    PROCEDURE hire_employee(p_name VARCHAR2, p_salary NUMBER) IS
    BEGIN
        INSERT INTO employees(name, salary)
        VALUES (p_name, p_salary);
    END;
    
    FUNCTION get_total_salary RETURN NUMBER IS
        v_total NUMBER;
    BEGIN
        SELECT SUM(salary) INTO v_total FROM employees;
        RETURN v_total;
    END;
END emp_pkg;
/
```

## 유용한 팁

- `DBMS_OUTPUT.PUT_LINE()`: 콘솔 출력 (먼저 `SET SERVEROUTPUT ON` 실행 필요)
- `COMMIT`: 트랜잭션 확정
- `ROLLBACK`: 트랜잭션 취소
- `/`: PL/SQL 블록 실행
- `;`: 각 문장 종료

## 참고사항

- PL/SQL 블록은 DECLARE, BEGIN, EXCEPTION, END로 구성됩니다
- 변수명은 관례상 `v_`로 시작하고, 파라미터는 `p_`로 시작합니다
- 커서 속성: `%FOUND`, `%NOTFOUND`, `%ROWCOUNT`, `%ISOPEN`
- 트랜잭션 처리를 위해 항상 COMMIT 또는 ROLLBACK을 명시적으로 호출하세요

---

이 문서는 PL/SQL 기본 문법을 빠르게 참고하기 위한 치트시트입니다.
