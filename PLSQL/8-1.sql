-- ========================================
-- 1.sql: star_Insert 트리거
-- ========================================
-- 트리거 타입: BEFORE INSERT (행 레벨)
-- 대상 테이블: moviestar
-- 목적: moviestar 테이블에 새로운 배우 정보 삽입 시 
--       누락된 컬럼(address, birthdate, gender)에 자동으로 값을 채움
-- ========================================

create or replace trigger star_Insert
before insert on moviestar  -- moviestar 테이블에 INSERT 되기 전에 실행
for each row                -- 각 행마다 개별적으로 실행 (행 레벨 트리거)
declare
    cnt int;  -- 카운터 변수 (나이 통계 계산에 사용)
begin
    -- ===== 1. ADDRESS 처리 =====
    -- 주소가 NULL인 경우 자동으로 값 생성
    if :new.address is null then
        -- '부산시-현재시각' 형식으로 주소 생성
        -- systimestamp: 현재 시스템 시간 (마이크로초 단위까지 포함)
        -- 각 배우마다 고유한 주소가 생성됨
        :new.address := '부산시-'||systimestamp;
    end if;
    
    -- ===== 2. BIRTHDATE 처리 =====
    -- 생년월일이 NULL인 경우 자동으로 값 생성
    if :new.birthdate is null then
        -- 1900년 1월 1일부터 랜덤한 날짜 생성
        -- date '1900-01-01': 기준 날짜
        -- dbms_random.value(0, 365 * 55): 0일 ~ 20,075일(약 55년) 사이의 랜덤 일수
        -- trunc: 소수점 버림으로 정수 변환
        -- 결과: 1900년~1955년 사이의 랜덤한 생년월일
        :new.birthdate := date '1900-01-01' + trunc(dbms_random.value(0, 365 * 55)); 
    end if;
    
    -- ===== 3. GENDER 처리 (복잡한 로직) =====
    -- 성별이 NULL인 경우 자동으로 값 생성
    if :new.gender is null then
        -- 3-1. 새로 삽입되는 배우보다 나이가 어린 배우들의 수 확인
        -- (birthdate가 클수록 나이가 어림)
        select count(*) into cnt
        from moviestar
        where birthdate > :new.birthdate;
        
        -- 3-2. 나이 어린 배우들이 존재하는 경우
        if cnt > 0 then
            -- 새 배우보다 어린 배우들의 성별 분포를 분석하여
            -- 가장 많은 성별을 선택 (동률인 경우 랜덤)
            select gender into :new.gender
            from (
                select gender
                from moviestar
                where birthdate > :new.birthdate  -- 새 배우보다 어린 배우들만
                group by gender                   -- 성별로 그룹화
                order by count(*) desc,           -- 많은 성별 순으로 정렬
                         dbms_random.value        -- 동률일 경우 랜덤 정렬
            )
            where rownum = 1;  -- 첫 번째 행만 선택 (가장 많은 성별)
            
            -- 예시: 
            -- 새 배우보다 어린 배우 중 male 5명, female 3명 → male 선택
            -- 새 배우보다 어린 배우 중 male 4명, female 4명 → 랜덤 선택
            
        -- 3-3. 나이 어린 배우들이 없는 경우 (가장 나이 많은 배우)
        else
            -- 랜덤하게 성별 결정 (50% 확률로 male 또는 female)
            -- dbms_random.value: 0 이상 1 미만의 랜덤 실수
            -- < 0.5이면 male, >= 0.5이면 female
            :new.gender := case when dbms_random.value < 0.5 then 'male' else 'female' end;
        end if;
    end if;
end;
/

-- ========================================
-- 트리거 동작 예시:
-- ========================================
-- INSERT 시도: INSERT INTO moviestar(name) VALUES ('John Doe');
--
-- 트리거 자동 처리:
-- - address: '부산시-2025-12-16 15:30:45.123456'
-- - birthdate: 예) 1920-05-15 (1900년~1955년 사이 랜덤)
-- - gender: 
--   * John Doe보다 어린 배우 존재 → 그들의 다수 성별 선택
--   * John Doe보다 어린 배우 없음 → 랜덤 선택 (male/female)
--
-- 최종 삽입 데이터:
-- ('John Doe', '부산시-2025-12-16...', 1920-05-15, 'male')
-- ========================================
