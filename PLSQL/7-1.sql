-- ========================================
-- 1.sql: 스튜디오 정보를 집계하여 studioinfo 테이블에 삽입
-- ========================================
-- 목적: studio, movie, moviestar, movieexec 테이블의 데이터를 조합하여
--       중첩 테이블을 포함한 studioinfo 테이블을 생성
-- ========================================

declare
    -- ===== 커서 선언부 =====
    -- 모든 스튜디오 정보를 가져오는 커서
    cursor studio_csr is select * from studio;
    
    -- 특정 스튜디오의 영화 정보를 가져오는 매개변수 커서
    -- 파라미터: sn - 스튜디오 이름
    cursor movie_csr(sn studio.name%type) is
        select * from movie where studioname = sn;
    
    -- 모든 영화배우의 이름을 가져오는 커서
    cursor allstar_csr is select name from moviestar;

    -- ===== 동적 SQL 문자열 선언부 =====
    -- studioinfo 테이블에 기본 정보 삽입하는 SQL
    -- 중첩 테이블(movie_tab, star_tab)은 빈 상태로 초기화
    si_ins varchar2(200) := 
        'insert into studioinfo values (:1, :2, :3, movie_tab(), star_tab())';

    -- studioinfo의 movies 중첩 테이블에 영화 정보 삽입하는 SQL
    -- mv_ty: 영화 타입 객체 (제목, 연도, 예산, 프로듀서)
    mov_ins varchar2(500) := 
        'insert into table(select movies from studioinfo where name = :1)
         values (mv_ty(:2, :3, :4, :5))';

    -- studioinfo의 stars 중첩 테이블에 배우 정보 삽입하는 SQL
    -- star_ty: 배우 타입 객체 (이름, 출연료, 등급)
    star_ins varchar2(500) := 
        'insert into table(select stars from studioinfo where name = :1)
         values (star_ty(:2, :3, :4))';

    -- ===== 작업용 변수 선언부 =====
    v_president varchar2(30);           -- 스튜디오 사장 이름
    v_producer  varchar2(30);           -- 영화 프로듀서 이름
    v_studio_names sys.odcivarchar2list; -- 모든 스튜디오 이름 목록
    v_studio_count number;              -- 스튜디오 총 개수
    v_random_star_count number;         -- 각 스튜디오당 할당할 배우 수 (랜덤)

begin
    -- ===== 1단계: 스튜디오 기본 정보 삽입 =====
    -- 모든 스튜디오 이름을 컬렉션에 일괄 저장
    select name bulk collect into v_studio_names from studio;
    v_studio_count := v_studio_names.count;

    -- 각 스튜디오에 대해 반복
    for s in studio_csr loop
        begin
            -- 스튜디오 사장 정보 조회
            -- presno(사장 인증번호)로 movieexec 테이블에서 사장 이름 찾기
            select name into v_president from movieexec where certno = s.presno;
        exception
            -- 사장 정보가 없는 경우 'Unknown'으로 처리
            when no_data_found then v_president := 'Unknown';
        end;

        -- studioinfo 테이블에 스튜디오 기본 정보 삽입
        -- (이름, 주소, 사장, 빈 영화 목록, 빈 배우 목록)
        execute immediate si_ins using s.name, s.address, v_president;
    end loop;

    -- ===== 2단계: 각 스튜디오의 영화 정보 삽입 =====
    for s in studio_csr loop
        -- 해당 스튜디오가 제작한 모든 영화에 대해 반복
        for m in movie_csr(s.name) loop
            begin
                -- 영화 프로듀서 정보 조회
                -- producerno(프로듀서 인증번호)로 movieexec 테이블에서 프로듀서 이름 찾기
                select name into v_producer from movieexec where certno = m.producerno;
            exception
                -- 프로듀서 정보가 없는 경우 'Unknown'으로 처리
                when no_data_found then v_producer := 'Unknown';
            end;

            -- studioinfo의 movies 중첩 테이블에 영화 정보 삽입
            execute immediate mov_ins using 
                s.name,                                      -- 스튜디오 이름
                m.title,                                     -- 영화 제목
                m.year,                                      -- 제작 연도
                trunc(dbms_random.value(1000000, 1000000000)), -- 랜덤 예산 (100만~10억)
                v_producer;                                  -- 프로듀서 이름
        end loop;
    end loop;

    -- ===== 3단계: 각 스튜디오에 배우 정보 랜덤 할당 =====
    -- 각 스튜디오마다 랜덤한 수의 배우를 랜덤하게 선택 (중복 가능)
    for s in studio_csr loop
        -- 이 스튜디오의 배우 수를 5~15명 사이로 랜덤 결정
        -- dbms_random.value(5, 16): 5 이상 16 미만의 실수 생성
        -- trunc: 소수점 이하 버림으로 정수 변환
        v_random_star_count := trunc(dbms_random.value(5, 16));
        
        -- 결정된 수만큼 배우를 랜덤하게 선택하여 삽입
        for i in 1..v_random_star_count loop
            -- 모든 배우 중에서 랜덤하게 1명 선택
            -- order by dbms_random.value: 랜덤 정렬
            -- fetch first 1 rows only: 첫 번째 행만 가져오기 (Oracle 12c 이상)
            for star in (select name from moviestar order by dbms_random.value fetch first 1 rows only) loop
                -- studioinfo의 stars 중첩 테이블에 배우 정보 삽입
                execute immediate star_ins using 
                    s.name,                                    -- 스튜디오 이름
                    star.name,                                 -- 배우 이름
                    trunc(dbms_random.value(10000, 100000000)), -- 랜덤 출연료 (1만~1억)
                    trunc(dbms_random.value(1, 11));           -- 랜덤 등급 (1~10)
            end loop;
        end loop;
    end loop;

    -- 모든 변경사항을 데이터베이스에 영구 저장
    commit;

exception
    -- 예외 발생 시 에러 메시지 출력
    when others then
        dbms_output.put_line('오류');
end;
/
