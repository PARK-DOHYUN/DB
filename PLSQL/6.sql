-- PL/SQL 블록 시작
declare
    -- 타입 선언부
    type s_ty is table of varchar2(200);  -- 문자열 배열 타입 정의
    type csr_ty is ref cursor;            -- 참조 커서 타입 정의 (동적 SQL용)

    -- 변수 선언부
    csr  csr_ty;  -- 동적 쿼리 실행을 위한 참조 커서
    
    -- 검색할 키워드 배열 초기화 (주소에서 검색할 문자열들)
    keys s_ty := s_ty('uk','_','california','ZZZ','new york','texas','chicago');

    -- SQL 쿼리 상수 정의
    -- 특정 주소를 포함하는 영화 임원 정보를 조회하는 쿼리
    exec_sql constant varchar2(500) := 'select * from movieexec where lower(address) like ''%''||lower(:1)||''%'' ';
    
    -- 특정 주소를 포함하는 영화 임원들의 평균 재산을 계산하는 쿼리
    avg_sql constant varchar2(500) := 'select avg(networth) from movieexec where lower(address) like ''%''||lower(:1)||''%'' ';

    -- 작업용 변수들
    e_str varchar2(200);    -- 실행할 SELECT 쿼리 문자열
    a_str varchar2(200);    -- 실행할 AVG 쿼리 문자열
    key_str varchar2(200);  -- 현재 검색 키워드
    
    -- 데이터 저장용 변수들
    e movieexec%rowtype;    -- movieexec 테이블의 한 행을 저장할 레코드 변수
    avg_value float;        -- 평균 재산액을 저장할 변수
    n integer;              -- 결과 행 번호를 저장할 카운터 변수
    
begin
    -- keys 배열의 모든 요소를 순회하며 처리
    for i in keys.first..keys.last loop
        
        -- 특수문자 '_' 처리 (SQL LIKE에서 와일드카드로 인식되므로 이스케이프 필요)
        if keys(i) = '_' then
            -- ESCAPE 절을 추가하여 '_'를 리터럴 문자로 처리
            e_str := exec_sql || ' escape ''\'' ';
            a_str := avg_sql || ' escape ''\'' ';
            key_str := '\_';  -- 백슬래시로 이스케이프 처리
        else
            -- 일반 문자열은 그대로 사용
            e_str := exec_sql;
            a_str := avg_sql;
            key_str := keys(i);
        end if;

        -- 동적 SQL 실행: 해당 주소를 가진 임원들의 평균 재산 계산
        execute immediate a_str into avg_value using key_str;

        -- 결과 출력: 평균 재산 정보
        if avg_value is null then
            -- 해당 주소를 가진 임원이 없는 경우
            dbms_output.put_line('['||i||']'||keys(i)||'가 주소에 있는 임원들 : 해당 정보 없음.');
        else
            -- 해당 주소를 가진 임원이 있는 경우, 평균 재산 출력
            -- FM 포맷: 앞/뒤 공백 제거, 999,999,999,999.00: 천단위 구분 및 소수점 2자리
            dbms_output.put_line('['||i||']'||keys(i)||'가 주소에 있는 임원들 : 평균 재산 액수 - '||to_char(avg_value, 'FM999,999,999,999.00')||'원');
        end if;

        -- 동적 SQL로 커서 오픈: 해당 주소를 가진 모든 임원 정보 조회
        open csr for e_str using key_str;
        
        n := 1;  -- 행 번호 초기화
        
        -- 커서에서 데이터를 한 행씩 가져와 처리
        loop
            fetch csr into e;           -- 커서에서 한 행을 e 레코드 변수로 가져옴
            exit when csr%notfound;     -- 더 이상 가져올 행이 없으면 루프 종료
            
            -- 개별 임원 정보 출력 (순번, 이름, 주소, 재산)
            dbms_output.put_line('      ('||n||') '||e.name||'('||e.address||'에 거주) : 재산 : '||to_char(e.networth, 'FM999,999,999,999.00')||'원');
            
            n := n + 1;  -- 다음 행 번호로 증가
        end loop;
        
        close csr;  -- 커서 닫기 (리소스 해제)
    end loop;
end;
/
-- PL/SQL 블록 종료 및 실행
