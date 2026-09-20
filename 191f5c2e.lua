--[[
	DataStoreManager.lua
	Type: ModuleScript
	Locatie: ServerScriptService (als kindje van/naast GameManager)

	Beheert het opslaan en laden van spelersvoortgang:
	coins, voertuigen, huizen, gamepasses, huidige baan.
]]

local DataStoreService = game:GetService("DataStoreService")
local IamsterdamStore = DataStoreService:GetDataStore("IamsterdamPlayerData_v1")

local DataStoreManager = {}

function DataStoreManager.LoadData(player)
	local success, data = pcall(function()
		return IamsterdamStore:GetAsync("Player_" .. player.UserId)
	end)

	if success and data then
		return data
	elseif not success then
		warn("[DataStoreManager] Fout bij laden van data voor " .. player.Name .. ": " .. tostring(data))
	end

	return nil
end

function DataStoreManager.SaveData(player, data)
	local success, errorMessage = pcall(function()
		IamsterdamStore:SetAsync("Player_" .. player.UserId, data)
	end)

	if success then
		print("[DataStoreManager] Data opgeslagen voor " .. player.Name)
	else
		warn("[DataStoreManager] Fout bij opslaan van data voor " .. player.Name .. ": " .. tostring(errorMessage))
	end

	return success
end

return DataStoreManager
