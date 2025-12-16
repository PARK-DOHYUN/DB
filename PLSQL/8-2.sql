-- ========================================
-- 2.sql: exec_update 트리거
-- ========================================
-- 트리거 타입: BEFORE UPDATE (행 레벨)
-- 대상 테이블: movieexec
-- 목적: movieexec 테이블의 임원 정보 업데이트 시 다양한 비즈니스 규칙 적용
--   1. 스튜디오/영화와 연관된 임원의 이름 변경 방지
--   2. 재산이 NULL로 업데이트되는 경우 최대값으로 설정
--   3. 재산 증가 시 조건에 따라 스튜디오 사장직 자동 할당
--   4. 배우로 활동 중인 임원의 주소에 특별 표시 추가
-- ========================================

create or replace trigger exec_update
before update on movieexec  -- movieexec 테이블이 UPDATE 되기 전에 실행
for each row                -- 각 행마다 개별적으로 실행 (행 레벨 트리거)
declare
    cnt int := 0;                        -- 일반 카운터 변수
    temp int := 0;                       -- 임시 카운터 변수
    avg_net movieexec.networth%type;     -- 평균 재산액 저장 변수
begin
    -- ===== 1. 이름 변경 제한 로직 =====
    -- 이 임원이 스튜디오 사장이거나 영화 프로듀서로 참여 중인 경우
    -- 이름 변경을 허용하지 않음 (데이터 무결성 보장)
    
    -- 1-1. 이 임원이 사장으로 있는 스튜디오 개수 확인
    select count(*) into cnt
    from studio
    where presno = :old.certno;  -- :old는 업데이트 전 값
    
    -- 1-2. 이 임원이 프로듀서로 참여한 영화 개수 확인
    select count(*) into temp
    from movie
    where producerno = :old.certno;
    
    -- 1-3. 총 연관 개수 계산 (스튜디오 + 영화)
    cnt := cnt + temp;
    
    -- 1-4. 이름이 변경되었고 다른 테이블과 연관이 있는 경우
    if :old.name != :new.name and cnt > 0 then
        -- 이름 변경을 취소하고 기존 이름 유지
        -- 이유: 외래키 관계로 인한 데이터 불일치 방지
        :new.name := :old.name;
    end if;
    
    -- ===== 2. 재산(networth) NULL 처리 =====
    -- 재산이 NULL로 업데이트되려는 경우
    if :new.networth is null then
        -- 모든 임원 중 최대 재산액으로 설정
        -- 업무 규칙: 재산 정보는 필수이며, 누락 시 최고액으로 추정
        select max(networth) into :new.networth
        from movieexec;
    end if;
    
    -- ===== 3. 재산 증가 시 스튜디오 사장직 자동 할당 로직 =====
    -- 재산이 증가한 경우 특정 조건 하에 랜덤 스튜디오의 사장으로 임명
    if :new.networth > :old.networth then
        -- 3-1. 현재 이 임원의 직책 확인
        cnt := 0;
        temp := 0;
        
        -- 사장으로 있는 스튜디오 개수
        select count(*) into cnt
        from studio
        where presno = :old.certno;
        
        -- 프로듀서로 참여한 영화 개수
        select count(*) into temp
        from movie
        where producerno = :old.certno;
        
        cnt := cnt + temp;  -- 총 직책 개수
        
        -- 3-2. 현재 아무 직책이 없는 경우
        if cnt = 0 then
            -- 3-2-1. 모든 임원의 평균 재산액 계산
            select avg(networth) into avg_net
            from movieexec;
            
            -- 3-2-2. 새로운 재산액이 평균보다 높은 경우
            if :new.networth > avg_net then
                -- 랜덤하게 선택된 스튜디오의 사장으로 임명
                update studio
                set presno = :old.certno  -- 이 임원을 사장으로 설정
                where name = (
                    -- 모든 스튜디오 중 랜덤하게 하나 선택
                    select name
                    from (select name from studio order by dbms_random.value)
                    where rownum = 1
                );
                
                -- 업무 논리:
                -- 1. 재산이 증가하여 평균 이상이 됨
                -- 2. 현재 직책이 없음
                -- 3. → 능력을 인정받아 스튜디오 사장으로 승진
            end if;
        end if;
    end if;
    
    -- ===== 4. 배우 겸업 표시 로직 =====
    -- 이 임원이 배우로도 활동 중인지 확인
    -- (movieexec의 name과 moviestar의 name이 같고, starsin에 출연 기록이 있는 경우)
    
    select count(*) into cnt
    from starsin
    where starname = :old.name;
    
    -- 배우로 출연한 기록이 있는 경우
    if cnt > 0 then
        -- 주소에 특별 표시 추가
        -- 주소를 대괄호로 감싸고 "에 배우가 삽니다!" 메시지 추가
        -- 예: '부산시 해운대구' → '[부산시 해운대구]에 배우가 삽니다!'
        :new.address := '['||:new.address||']에 배우가 삽니다!';
    end if;
end;
/

-- ========================================
-- 트리거 동작 예시:
-- ========================================
-- 
-- 예시 1: 이름 변경 시도 (연관 데이터 있음)
-- UPDATE movieexec SET name = 'New Name' WHERE certno = 123;
-- → 123번 임원이 스튜디오 사장이거나 영화 프로듀서인 경우
-- → 이름 변경 취소, 기존 이름 유지
--
-- 예시 2: 재산 NULL로 업데이트
-- UPDATE movieexec SET networth = NULL WHERE certno = 456;
-- → networth가 모든 임원 중 최대값으로 자동 설정
--
-- 예시 3: 재산 증가 (직책 없음 + 평균 이상)
-- UPDATE movieexec SET networth = 50000000 WHERE certno = 789;
-- (가정: 평균 재산 30000000, 789번 임원 현재 직책 없음)
-- → 랜덤 스튜디오의 사장으로 자동 임명
--
-- 예시 4: 배우 겸업 중인 임원
-- UPDATE movieexec SET address = '서울시 강남구' WHERE certno = 101;
-- (가정: 101번 임원이 starsin 테이블에 출연 기록 있음)
-- → address가 '[서울시 강남구]에 배우가 삽니다!'로 변경
-- ========================================
