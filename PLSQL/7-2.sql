-- ========================================
-- 2.sql: 영화 임원 정보를 집계하여 movieexecinfo 테이블에 삽입
-- ========================================
-- 목적: movieexec, movie, studio 테이블의 데이터를 조합하여
--       중첩 테이블을 포함한 movieexecinfo 테이블을 생성
-- ========================================

declare
    -- ===== 커서 선언부 =====
    -- 모든 영화 임원(영화 제작자/사장) 정보를 가져오는 커서
    cursor exec_csr is select * from movieexec;
    
    -- 특정 임원이 프로듀서로 참여한 영화 정보를 가져오는 매개변수 커서
    -- 파라미터: cert - 임원 인증번호
    cursor movie_csr(cert movieexec.certno%type) is
        select title, year from movie where producerno = cert;
    
    -- 특정 임원이 사장으로 있는 스튜디오 정보를 가져오는 매개변수 커서
    -- 파라미터: cert - 임원 인증번호
    cursor studio_csr(cert movieexec.certno%type) is
        select name from studio where presno = cert;

    -- ===== 동적 SQL 문자열 선언부 =====
    -- movieexecinfo 테이블에 기본 정보 삽입하는 SQL
    -- 중첩 테이블(movie_tab, studio_tab)은 빈 상태로 초기화
    mei_ins varchar2(300) :=
        'insert into movieexecinfo values (:1, :2, :3, movie_tab(), studio_tab())';

    -- movieexecinfo의 movies 중첩 테이블에 영화 정보 삽입하는 SQL
    -- movie_ty: 영화 타입 객체 (제목, 연도, 계약일, 급여)
    mov_ins varchar2(500) :=
        'insert into table(select movies from movieexecinfo where name = :1)
         values (movie_ty(:2, :3, :4, :5))';

    -- movieexecinfo의 studios 중첩 테이블에 스튜디오 정보 삽입하는 SQL
    -- studio_ty: 스튜디오 타입 객체 (이름, 직원 수)
    std_ins varchar2(500) :=
        'insert into table(select studios from movieexecinfo where name = :1)
         values (studio_ty(:2, :3))';

    -- ===== 작업용 변수 선언부 =====
    v_contract_date date;    -- 영화 제작 계약 날짜
    v_salary number;         -- 임원 급여
    v_emp_count number;      -- 스튜디오 직원 수

begin
    -- ===== 1단계: 영화 임원 기본 정보 삽입 =====
    -- 모든 임원에 대해 반복
    for e in exec_csr loop
        -- movieexecinfo 테이블에 임원 기본 정보 삽입
        -- (이름, 주소, 재산, 빈 영화 목록, 빈 스튜디오 목록)
        execute immediate mei_ins using e.name, e.address, e.networth;
    end loop;

    -- ===== 2단계: 각 임원이 프로듀서로 참여한 영화 정보 삽입 =====
    for e in exec_csr loop
        -- 해당 임원이 프로듀서로 참여한 모든 영화에 대해 반복
        for m in movie_csr(e.certno) loop
            -- 계약 날짜 생성: 영화 제작 연도 기준으로 1~23개월 전
            -- to_date('01-01-' || m.year, 'DD-MM-YYYY'): 영화 연도의 1월 1일
            -- add_months(..., -N): N개월 전 날짜 계산
            -- dbms_random.value(1, 24): 1 이상 24 미만의 랜덤 실수
            v_contract_date := add_months(to_date('01-01-' || m.year, 'DD-MM-YYYY'), -trunc(dbms_random.value(1, 24)));
            
            -- 랜덤 급여 생성: 100만~10억 사이
            v_salary := trunc(dbms_random.value(1000000, 1000000000));

            -- movieexecinfo의 movies 중첩 테이블에 영화 정보 삽입
            execute immediate mov_ins using 
                e.name,           -- 임원 이름
                m.title,          -- 영화 제목
                m.year,           -- 제작 연도
                v_contract_date,  -- 계약 날짜
                v_salary;         -- 급여
        end loop;
    end loop;

    -- ===== 3단계: 각 임원이 사장으로 있는 스튜디오 정보 삽입 =====
    for e in exec_csr loop
        -- 해당 임원이 사장으로 있는 모든 스튜디오에 대해 반복
        for s in studio_csr(e.certno) loop
            -- 랜덤 직원 수 생성: 10~4999명 사이
            v_emp_count := trunc(dbms_random.value(10, 5000));
            
            -- movieexecinfo의 studios 중첩 테이블에 스튜디오 정보 삽입
            execute immediate std_ins using 
                e.name,       -- 임원 이름 (사장)
                s.name,       -- 스튜디오 이름
                v_emp_count;  -- 직원 수
        end loop;
    end loop;

    -- 모든 변경사항을 데이터베이스에 영구 저장
    commit;

exception
    -- 예외 발생 시 모든 변경사항 롤백 (취소)
    when others then
        rollback;
end;
/
