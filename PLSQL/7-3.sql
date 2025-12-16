-- ========================================
-- 3.sql: 예외 처리 및 제약조건 테스트
-- ========================================
-- 목적: Oracle 데이터베이스의 다양한 제약조건 위반 상황을 테스트하고
--       각 예외를 적절히 처리하는 방법을 시연
-- 테스트 제약조건:
--   1. NOT NULL 제약조건
--   2. CHECK 제약조건
--   3. UNIQUE (PRIMARY KEY) 제약조건
--   4. FOREIGN KEY 제약조건 (부모-자식 관계)
-- ========================================

-- 기존 테이블이 있다면 CASCADE 옵션으로 함께 삭제
-- CASCADE CONSTRAINTS: 이 테이블을 참조하는 다른 테이블의 외래키 제약조건도 함께 삭제
drop table temp cascade constraints
/
drop table test cascade constraints
/

-- ===== TEST 테이블 생성 =====
-- 부모 테이블 역할
create table test (
    name    varchar(100) primary key,  -- 기본키: 중복 불가, NULL 불가
    age     number(3) not null,        -- NOT NULL 제약조건: NULL 입력 불가
    address varchar(200),              -- NULL 허용
    check(age > 10 and age < 110)     -- CHECK 제약조건: age는 10 초과 110 미만이어야 함
)
/

-- ===== TEMP 테이블 생성 =====
-- 자식 테이블 역할 (test 테이블을 참조)
create table temp (
    num     number(3) primary key,              -- 기본키
    name    varchar(100) references test(name)  -- 외래키: test 테이블의 name 컬럼을 참조
)
/

-- 초기 데이터 삽입 (정상 케이스)
insert into test values ('H0', 23, '부산시 남구');
insert into temp values (0, 'H0');

-- ===== PL/SQL 블록 시작 =====
declare
    -- ===== 컬렉션 타입 선언 =====
    type    n_type is table of test.name%type;  -- 이름 타입의 배열
    type    a_type is table of test.age%type;   -- 나이 타입의 배열
    
    -- ===== 테스트 데이터 배열 선언 및 초기화 =====
    -- test_n: 테스트할 이름 배열 (의도적으로 중복된 'H3' 포함)
    test_n   n_type := n_type('H1', 'H2', 'H3', 'H3', 'H4');
    
    -- test_a: 테스트할 나이 배열
    --   30: 정상 (10 < 30 < 110)
    --   NULL: NOT NULL 제약조건 위반
    --   28: 정상
    --   40: 정상
    --   5: CHECK 제약조건 위반 (5 <= 10)
    test_a   a_type := a_type(30, NULL, 28, 40, 5);
    
    -- temp_n: test_n의 복사본을 저장할 배열 (나중에 FK 테스트용)
    temp_n  n_type := n_type();
    
    -- ===== 동적 SQL 문자열 =====
    sql_str     varchar(200) := 'insert into test values (:1, :2, :3)';  -- test 테이블 삽입
    sql_str1     varchar(200) := 'insert into temp values (:1, :2)';     -- temp 테이블 삽입
    
    -- ===== 사용자 정의 예외 선언 =====
    not_null exception;   -- NOT NULL 제약조건 위반
    check_ exception;     -- CHECK 제약조건 위반
    unique_ exception;    -- UNIQUE(PRIMARY KEY) 제약조건 위반
    fk_1 exception;       -- FOREIGN KEY 제약조건 위반 (부모 테이블에 데이터 없음)
    fk_2 exception;       -- FOREIGN KEY 제약조건 위반 (자식 테이블에서 참조 중인 부모 데이터 삭제 시도)

    -- ===== PRAGMA EXCEPTION_INIT: 사용자 정의 예외와 Oracle 에러 코드 매핑 =====
    -- -1400: ORA-01400 (NOT NULL 제약조건 위반)
    pragma exception_init(not_null,-1400);
    
    -- -2290: ORA-02290 (CHECK 제약조건 위반)
    pragma exception_init(check_,-2290);
    
    -- -1: ORA-00001 (UNIQUE 제약조건 위반)
    pragma exception_init(unique_, -1);
    
    -- -2291: ORA-02291 (FK 부모키 없음 - 자식이 참조할 부모 데이터가 없음)
    pragma exception_init(fk_1, -2291);
    
    -- -2292: ORA-02292 (FK 자식 레코드 존재 - 자식이 참조 중인 부모 데이터 삭제/수정 불가)
    pragma exception_init(fk_2, -2292);
    
begin
    -- temp_n에 test_n 배열 복사
    temp_n := test_n;
    
    -- ===== 메인 루프: 각 테스트 케이스 실행 =====
    for i in test_n.first..test_n.last loop
        begin
            -- ===== TEST 테이블에 데이터 삽입 =====
            -- 이름, 나이, 랜덤 주소 삽입
            -- dbms_random.string('x', 5): 숫자+대문자 5자
            -- dbms_random.string('a', 10): 소문자 10자
            execute immediate sql_str using 
                test_n(i), 
                test_a(i), 
                dbms_random.string('x',5)||' '||dbms_random.string('a',10);
            
            -- ===== TEMP 테이블에 데이터 삽입 =====
            -- 인덱스 i와 이름 삽입
            execute immediate sql_str1 using i, temp_n(i);
            
            -- ===== 특정 인덱스에서 추가 작업 수행 =====
            -- i = 1 (첫 번째 요소): FK 제약조건 테스트
            if i = test_n.first then
                -- temp 테이블에서 참조 중인 test 테이블의 데이터 삭제 시도
                -- → FK_2 예외 발생 (자식이 참조 중인 부모 데이터 삭제 불가)
                delete from test
                where name = test_n(i);
                
            -- i = 3 (세 번째 요소): FK 제약조건 테스트
            elsif i = 3 then
                -- temp 테이블의 외래키를 존재하지 않는 값으로 업데이트 시도
                -- 'H5'는 test 테이블에 존재하지 않음
                -- → FK_1 예외 발생 (참조할 부모 데이터가 없음)
                update temp
                set name = 'H5'
                where num = 3;
            end if;
            
        exception
            -- ===== 예외 처리부: 각 제약조건 위반 케이스별 처리 =====
            
            -- NOT NULL 제약조건 위반 (age에 NULL 삽입 시)
            when not_null then
                dbms_output.put_line(i||' : NOT NULL ');
                
            -- CHECK 제약조건 위반 (age가 10 이하 또는 110 이상일 때)
            when check_ then
                dbms_output.put_line(i||' : CHECK ');
                
            -- UNIQUE (PRIMARY KEY) 제약조건 위반 (중복된 name 삽입 시)
            when unique_ then
                dbms_output.put_line(i||' : UNIQUE ');
                
            -- FOREIGN KEY 제약조건 위반 - 부모 키 없음
            -- (temp 테이블에 삽입 시 test 테이블에 해당 name이 없을 때)
            when fk_1 then
                dbms_output.put_line(i||' : FK 부모');

            -- FOREIGN KEY 제약조건 위반 - 자식 레코드 존재
            -- (temp 테이블에서 참조 중인 test 테이블의 레코드 삭제/수정 시)
            when fk_2 then
                dbms_output.put_line(i||' : FK 자식');
                
            -- 기타 모든 예외 처리
            when others then
                dbms_output.put_line(i||' : 오라클 에러 발생 !!!');
        end;
    end loop;
    
    -- ===== 결과 출력부 =====
    
    -- 구분선 출력 (별표 50개)
    dbms_output.put_line(lpad(' ', 50, '*'));
    
    -- TEST 테이블의 최종 데이터 출력
    for t in (select * from test) loop
        dbms_output.put_line(t.name||', '||t.age||', '||t.address);
    end loop;
    
    -- 구분선 출력
    dbms_output.put_line(lpad(' ', 50, '*'));
    
    -- TEMP 테이블의 최종 데이터 출력
    for t in (select * from temp) loop
        dbms_output.put_line(t.num||', '||t.name);
    end loop;
end;
/

-- ========================================
-- 예상 실행 결과 분석:
-- ========================================
-- 인덱스 1 ('H1', 30): 정상 삽입 후 DELETE 시도 → FK_2 예외 (자식이 참조 중)
-- 인덱스 2 ('H2', NULL): NOT NULL 예외 (age가 NULL)
-- 인덱스 3 ('H3', 28): 정상 삽입 후 UPDATE 시도 → FK_1 예외 (H5가 부모에 없음)
-- 인덱스 4 ('H3', 40): UNIQUE 예외 (H3 중복)
-- 인덱스 5 ('H4', 5): CHECK 예외 (age가 10 이하)
--
-- 최종 test 테이블: H0, H1, H3만 존재
-- 최종 temp 테이블: (0, H0), (1, H1), (3, H3)만 존재
-- ========================================
