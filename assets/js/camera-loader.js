/**
 * LiveCameraLinks Camera Loader v1.2.0
 * JSON hierarchy loader with world-ready identifiers.
 *
 * Identifier priority:
 *   cameraId -> slug -> legacy id
 */
(() => {
  "use strict";

  const DEFAULT_BASE = "/camera";

  function normalizeBase(base) {
    return String(base || DEFAULT_BASE).replace(/\/+$/, "");
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      cache: options.cache || "no-store",
      credentials: "same-origin",
      headers: { Accept: "application/json" }
    });

    if (!response.ok) {
      throw new Error(
        `LiveCameraLoader: ${response.status} ${response.statusText} - ${url}`
      );
    }

    try {
      return await response.json();
    } catch (_) {
      throw new Error(`LiveCameraLoader: invalid JSON - ${url}`);
    }
  }

  function cameraCountOf(doc) {
    return Array.isArray(doc?.cameras) ? doc.cameras.length : 0;
  }

  function primaryId(camera) {
    return camera?.cameraId || camera?.slug || camera?.id || null;
  }

  function normalizeCamera(camera, context = {}) {
    if (!camera || typeof camera !== "object") return null;

    const normalized = {
      ...camera,
      category: camera.category || context.category || null,
      prefectureCode:
        camera.prefectureCode || context.prefecture || null,
      areaCode:
        camera.areaCode || context.area || null
    };

    normalized.primaryId = primaryId(normalized);
    return normalized;
  }

  function matchesIdentifier(camera, value) {
    if (!camera || !value) return false;

    return (
      camera.cameraId === value ||
      camera.slug === value ||
      camera.id === value
    );
  }

  function uniqueCameras(cameras) {
    const map = new Map();

    for (const camera of cameras || []) {
      const key = primaryId(camera);
      if (!key) continue;

      if (!map.has(key)) {
        map.set(key, camera);
      }
    }

    return [...map.values()];
  }

  class CameraLoader {
    constructor(options = {}) {
      this.base = normalizeBase(options.base);
      this.cache = new Map();
      this.useMemoryCache =
        options.useMemoryCache !== false;
    }

    clearCache() {
      this.cache.clear();
    }

    async _load(path, options = {}) {
      const url =
        `${this.base}/${path.replace(/^\/+/, "")}`;

      if (
        this.useMemoryCache &&
        !options.force &&
        this.cache.has(url)
      ) {
        return this.cache.get(url);
      }

      const promise = fetchJson(url, options);

      if (
        this.useMemoryCache &&
        !options.force
      ) {
        this.cache.set(url, promise);
      }

      try {
        return await promise;
      } catch (error) {
        this.cache.delete(url);
        throw error;
      }
    }

    loadMaster(options = {}) {
      return this._load(
        "camera_index.json",
        options
      );
    }

    loadCategoryIndex(category, options = {}) {
      if (!category) {
        throw new Error("category is required");
      }

      return this._load(
        `${category}/index.json`,
        options
      );
    }

    loadPrefectureIndex(
      category,
      prefecture,
      options = {}
    ) {
      if (!category || !prefecture) {
        throw new Error(
          "category and prefecture are required"
        );
      }

      return this._load(
        `${category}/${prefecture}/index.json`,
        options
      );
    }

    async loadArea(
      category,
      prefecture,
      area,
      options = {}
    ) {
      if (!category || !prefecture || !area) {
        throw new Error(
          "category, prefecture and area are required"
        );
      }

      const doc = await this._load(
        `${category}/${prefecture}/${area}.json`,
        options
      );

      const cameras =
        Array.isArray(doc?.cameras)
          ? doc.cameras
              .map(camera =>
                normalizeCamera(camera, {
                  category,
                  prefecture,
                  area
                })
              )
              .filter(Boolean)
          : [];

      return {
        ...doc,
        category:
          doc?.category || category,
        prefecture:
          doc?.prefecture || prefecture,
        area:
          doc?.area || area,
        cameraCount:
          cameras.length,
        cameras
      };
    }

    async loadPrefecture(
      category,
      prefecture,
      options = {}
    ) {
      const index =
        await this.loadPrefectureIndex(
          category,
          prefecture,
          options
        );

      const areas =
        Array.isArray(index?.areas)
          ? index.areas
          : [];

      const docs =
        await Promise.all(
          areas.map(item => {
            const area =
              item.area ||
              String(item.file || "")
                .replace(/\.json$/i, "");

            return this.loadArea(
              category,
              prefecture,
              area,
              options
            );
          })
        );

      return {
        category,
        prefecture,
        cameraCount:
          docs.reduce(
            (sum, doc) =>
              sum + cameraCountOf(doc),
            0
          ),
        areas: docs
      };
    }

    async loadAreaAcrossCategories(
      prefecture,
      area,
      options = {}
    ) {
      const master =
        await this.loadMaster(options);

      const categories =
        Object.keys(
          master?.cameraCategories || {}
        );

      const results =
        await Promise.all(
          categories.map(async category => {
            try {
              return await this.loadArea(
                category,
                prefecture,
                area,
                options
              );
            } catch (error) {
              if (
                options.ignoreMissing !== false
              ) {
                return null;
              }

              throw error;
            }
          })
        );

      const documents =
        results.filter(Boolean);

      const cameras =
        uniqueCameras(
          documents.flatMap(
            doc => doc.cameras || []
          )
        );

      return {
        prefecture,
        area,
        cameraCount:
          cameras.length,
        categories:
          documents,
        cameras
      };
    }

    async loadPrefectureAcrossCategories(
      prefecture,
      options = {}
    ) {
      if (!prefecture) {
        throw new Error(
          "prefecture is required"
        );
      }

      const master =
        await this.loadMaster(options);

      const categories =
        Object.keys(
          master?.cameraCategories || {}
        );

      const results =
        await Promise.all(
          categories.map(async category => {
            try {
              return await this.loadPrefecture(
                category,
                prefecture,
                options
              );
            } catch (error) {
              if (
                options.ignoreMissing !== false
              ) {
                return null;
              }

              throw error;
            }
          })
        );

      const categoryDocs =
        results.filter(Boolean);

      const areas =
        categoryDocs.flatMap(
          categoryDoc =>
            (categoryDoc.areas || []).map(
              areaDoc => ({
                ...areaDoc,
                category:
                  categoryDoc.category
              })
            )
        );

      const cameras =
        uniqueCameras(
          areas.flatMap(
            area => area.cameras || []
          )
        );

      return {
        prefecture,
        cameraCount:
          cameras.length,
        categories:
          categoryDocs,
        areas,
        cameras
      };
    }

    async findByIdentifier(
      identifier,
      options = {}
    ) {
      if (!identifier) return null;

      const master =
        await this.loadMaster(options);

      const categories =
        Object.keys(
          master?.cameraCategories || {}
        );

      for (const category of categories) {
        const categoryIndex =
          await this.loadCategoryIndex(
            category,
            options
          );

        const prefectures =
          Array.isArray(
            categoryIndex?.prefectures
          )
            ? categoryIndex.prefectures
            : [];

        for (const pref of prefectures) {
          const prefecture =
            pref.prefecture;

          if (!prefecture) continue;

          const prefIndex =
            await this.loadPrefectureIndex(
              category,
              prefecture,
              options
            );

          for (
            const item of
            (prefIndex?.areas || [])
          ) {
            const area =
              item.area ||
              String(item.file || "")
                .replace(/\.json$/i, "");

            const doc =
              await this.loadArea(
                category,
                prefecture,
                area,
                options
              );

            const camera =
              (doc.cameras || []).find(
                c =>
                  matchesIdentifier(
                    c,
                    identifier
                  )
              );

            if (camera) {
              return camera;
            }
          }
        }
      }

      return null;
    }

    findById(id, options = {}) {
      return this.findByIdentifier(
        id,
        options
      );
    }

    findByCameraId(
      cameraId,
      options = {}
    ) {
      return this.findByIdentifier(
        cameraId,
        options
      );
    }

    findBySlug(
      slug,
      options = {}
    ) {
      return this.findByIdentifier(
        slug,
        options
      );
    }
  }

  CameraLoader.primaryId =
    primaryId;

  CameraLoader.matchesIdentifier =
    matchesIdentifier;

  window.LiveCameraLoader =
    CameraLoader;
})();
