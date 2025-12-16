-- ========================================
-- 4.sql: starplays_trigger 트리거
-- ========================================
-- 트리거 타입: INSTEAD OF INSERT (뷰 레벨)
-- 대상: starplays 뷰
-- 목적: starplays 뷰에 대한 INSERT 작업을 가로채어
--       실제 테이블(movie, moviestar, starsin)에 적절하게 데이터 삽입
--       - 존재하지 않는 영화 자동 생성
--       - 존재하지 않는 배우 자동 생성
--       - 출연 정보(starsin) 삽입
-- ========================================
-- 주의: starplays는 뷰(view)이므로 직접 INSERT 불가
--       INSTEAD OF 트리거를 통해 뷰에 대한 INSERT를 실제 테이블 작업으로 변환
-- ========================================

create or replace trigger starplays_trigger
instead of insert on starplays  -- starplays 뷰에 대한 INSERT를 가로챔
for each row                    -- 각 행마다 개별적으로 실행
declare
    cnt int;                           -- 카운터 변수
    max_certno movieexec.certno%type;  -- 프로듀서 인증번호 저장 변수
begin
    -- ===== 1. 영화(MOVIE) 존재 여부 확인 및 생성 =====
    
    -- 1-1. 해당 제목과 연도의 영화가 이미 존재하는지 확인
    -- 영화는 (title, year) 조합으로 식별됨
    select count(*) into cnt
    from movie
    where title = :new.title and year = :new.year;
    
    -- 1-2. 영화가 존재하지 않는 경우 자동 생성
    if cnt = 0 then
        -- 1-2-1. 가장 많은 영화를 제작한 프로듀서 찾기
        -- (동률인 경우 랜덤 선택)
        select producerno into max_certno
        from (
            select producerno, count(*) cnt  -- 프로듀서별 제작 영화 수
            from movie
            where producerno is not null
            group by producerno              -- 프로듀서별로 그룹화
            order by cnt desc,               -- 많은 제작 수 순으로 정렬 (내림차순)
                     dbms_random.value       -- 동률일 경우 랜덤
        )
        where rownum = 1;  -- 첫 번째 행만 선택 (가장 많이 제작한 프로듀서)
        
        -- 1-2-2. 최소 정보로 영화 레코드 생성
        insert into movie(title, year, producerno)
        values (:new.title, :new.year, max_certno);
        
        -- 업무 논리:
        -- - 출연 정보 입력 시 영화가 없으면 자동 생성
        -- - 경험 많은 프로듀서(가장 많이 제작한)를 배정
        -- - 나머지 정보(length, incolor 등)는 movie_insert 트리거가 처리
    end if;
    
    -- ===== 2. 배우(MOVIESTAR) 존재 여부 확인 및 생성 =====
    
    -- 2-1. 해당 이름의 배우가 이미 존재하는지 확인
    select count(*) into cnt
    from moviestar
    where name = :new.name;
    
    -- 2-2. 배우가 존재하지 않는 경우 자동 생성
    if cnt = 0 then
        insert into moviestar(name, address, gender, birthdate)
        values (
            :new.name,  -- 배우 이름
            
            -- 주소: '부산시-현재시각' 형식으로 자동 생성
            '부산시-'||systimestamp,
            
            -- 성별: 가장 최근에 태어난 배우와 같은 성별로 설정
            -- (신인 배우는 최근 세대의 성별 분포를 따른다고 가정)
            (select gender
             from (
                 select gender
                 from moviestar
                 where birthdate is not null     -- 생년월일 정보가 있는 배우만
                 order by birthdate desc,        -- 최근 생년월일 순 (내림차순)
                          dbms_random.value      -- 동일 생년월일이면 랜덤
             )
             where rownum = 1),  -- 가장 최근 배우의 성별
            
            -- 생년월일: 1980년 1월 1일 기준으로 0~45년 후의 랜덤 날짜
            -- 결과: 1980년~2025년 사이의 생년월일 (현대 배우)
            date '1980-01-01' + trunc(dbms_random.value(0, 365 * 45))
        );
        
        -- 업무 논리:
        -- - 출연 정보 입력 시 배우가 없으면 자동 생성
        -- - 주소는 고유하게 자동 생성 (시스템 타임스탬프 사용)
        -- - 성별은 최근 세대 배우들의 경향을 따름
        -- - 생년월일은 현대 배우 연령대로 설정 (1980~2025년생)
    end if;
    
    -- ===== 3. 출연 정보(STARSIN) 삽입 =====
    -- 영화와 배우가 준비되었으므로 출연 정보 삽입
    insert into starsin(movietitle, movieyear, starname)
    values (:new.title, :new.year, :new.name);
    
    -- 최종 결과:
    -- 1. movie 테이블: 영화 없으면 생성, 있으면 그대로
    -- 2. moviestar 테이블: 배우 없으면 생성, 있으면 그대로
    -- 3. starsin 테이블: 출연 정보 삽입 (항상 실행)
end;
/

-- ========================================
-- 트리거 동작 예시:
-- ========================================
--
-- starplays 뷰 구조 (가정):
-- CREATE VIEW starplays AS
-- SELECT m.title, m.year, ms.name
-- FROM movie m
-- JOIN starsin si ON m.title = si.movietitle AND m.year = si.movieyear
-- JOIN moviestar ms ON si.starname = ms.name;
--
-- INSERT 시도:
-- INSERT INTO starplays(title, year, name) 
-- VALUES ('Avatar 3', 2025, 'New Actor');
--
-- 트리거 처리 과정:
--
-- [1단계] 영화 확인
-- - 'Avatar 3', 2025 영화가 movie 테이블에 없음
-- - 가장 많이 제작한 프로듀서(예: certno=100) 선택
-- - INSERT INTO movie: ('Avatar 3', 2025, 100)
-- - movie_insert 트리거가 length, incolor 등 자동 설정
--
-- [2단계] 배우 확인
-- - 'New Actor' 배우가 moviestar 테이블에 없음
-- - 최근 배우의 성별(예: 'female') 확인
-- - INSERT INTO moviestar: 
--   ('New Actor', '부산시-2025-12-16...', 'female', 1995-03-15)
-- - star_Insert 트리거는 이미 값이 있으므로 실행되지만 변경 없음
--
-- [3단계] 출연 정보 삽입
-- - INSERT INTO starsin: ('Avatar 3', 2025, 'New Actor')
--
-- 최종 결과:
-- - movie 테이블: 'Avatar 3' 영화 추가
-- - moviestar 테이블: 'New Actor' 배우 추가
-- - starsin 테이블: 출연 관계 추가
--
-- ========================================
-- INSTEAD OF 트리거의 장점:
-- ========================================
-- 1. 뷰에 대한 INSERT를 가능하게 함
-- 2. 복잡한 조인 뷰에 대한 데이터 입력 간소화
-- 3. 참조 무결성 자동 보장 (관련 데이터 자동 생성)
-- 4. 사용자는 단순히 뷰에 INSERT만 하면 됨
-- 5. 복잡한 비즈니스 로직을 트리거에 캡슐화
-- ========================================
